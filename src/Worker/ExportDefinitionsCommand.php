<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Cron\CronExpression;
use LiquidMonitorConnector\Cron;
use LiquidMonitorConnector\Scheduler\Clock;
use LiquidMonitorConnector\Scheduler\CronDefinition;
use LiquidMonitorConnector\Scheduler\CronDefinitionCatalog;
use LiquidMonitorConnector\Scheduler\SchedulerConfig;
use LiquidMonitorConnector\Version;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exports scheduler configuration and cron definitions for `lqcron`
 * (docs/v4-contract.md § 4). `lqcron sync` runs it in the release slot:
 *
 *     php vendor/bin/monitor-worker export-definitions --output=temp/lqcron/export.json
 *
 * The file carries the API key (mode 0640, same sensitivity as local.neon).
 * Any invalid definition fails the whole export, so lqcron keeps the last valid set
 * and reports `definitions.failed` instead of silently dropping a cron.
 */
final class ExportDefinitionsCommand extends Command
{
	public const NAME = 'export-definitions';

	public const FORMAT = 1;

	/**
	 * @param \Closure(string|null): \Nette\DI\Container|null $containerFactory
	 */
	public function __construct(private \Closure|null $containerFactory = null)
	{
		parent::__construct(self::NAME);
	}

	/**
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException on an invalid definition
	 */
	public static function build(SchedulerConfig $config, CronDefinitionCatalog $catalog, Cron $cron, string $bootstrap, string $workingDirectory): array
	{
		$warnings = [];
		$crons = [];
		$definitions = $catalog->all($config->environment);

		foreach ($definitions as $definition) {
			$crons[] = self::cronEntry($definition, $catalog, $definitions, $warnings);
		}

		self::assertNoAfterCycle($crons);

		$phpBinary = $config->phpBinary ?? \PHP_BINARY;

		$configExport = [
			// lqcron runs in local and in shadow mode; `shadow: true` alone is enough
			// to turn shadow mode on (§ 13.2), so the binary must not see it as off.
			'enabled' => $config->isSchedulerRunning(),
			'shadow' => $config->shadow,
			'environment' => $config->environment,
			'state_dir' => $config->stateDir,
			'timezone' => $config->timezone,
			'max_parallel' => $config->maxParallel,
			'starvation_seconds' => $config->starvationSeconds,
			'run_log_max_bytes' => $config->runLogMaxBytes,
			'kill_grace_seconds' => $config->killGraceSeconds,
			'report_log_tail' => $config->reportLogTail,
			'log_redactor' => $config->logRedactor !== null,
			'remote_commands' => $config->remoteCommands,
			'resources' => (object) $config->resources,
			'services' => (object) $config->services,
			'php_binary' => $phpBinary,
			'bootstrap' => $config->bootstrap ?? $bootstrap,
			'url' => $cron->getUrl(),
			'api_key' => $cron->getApiKey(),
			'verify_tls' => $cron->getVerifyTls(),
		];

		return [
			'format' => self::FORMAT,
			'generated_at' => Clock::iso(Clock::nowMs()),
			'connector_version' => Version::CURRENT,
			'app_path' => \realpath($workingDirectory) ?: $workingDirectory,
			'php_binary' => \PHP_BINARY,
			'config_files' => $catalog->configFiles(),
			'hash' => \hash('sha256', Json::encode(['config' => $configExport, 'crons' => $crons])),
			'config' => $configExport,
			'crons' => $crons,
			'warnings' => $warnings,
		];
	}

	public static function writeAtomically(string $target, string $content): void
	{
		$directory = \dirname($target);

		FileSystem::createDir($directory, 0770);

		$temporary = $target . '.' . \getmypid() . '.tmp';
		$previousUmask = \umask(0027);

		try {
			if (\file_put_contents($temporary, $content) === false) {
				throw new \RuntimeException("export-definitions: cannot write {$temporary}");
			}

			\chmod($temporary, 0640);

			if (!\rename($temporary, $target)) {
				throw new \RuntimeException("export-definitions: cannot move export to {$target}");
			}
		} finally {
			\umask($previousUmask);
		}
	}

	protected function configure(): void
	{
		$this
			->setDescription('Export scheduler configuration and #[PullCron] definitions for lqcron.')
			->addOption('bootstrap', null, InputOption::VALUE_REQUIRED, 'PHP file returning a Nette DI Container (default: the package bootstrap)')
			->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write to this file atomically instead of stdout')
			->addOption('json', null, InputOption::VALUE_NONE, 'Accepted for compatibility; the export is always JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$bootstrapOption = $input->getOption('bootstrap');
		$bootstrap = ContainerBootstrap::resolvePath(\is_string($bootstrapOption) ? $bootstrapOption : null);
		$container = $this->containerFactory !== null ? ($this->containerFactory)($bootstrap) : ContainerBootstrap::load($bootstrap);

		$config = $container->getByType(SchedulerConfig::class);
		$catalog = $container->getByType(CronDefinitionCatalog::class);
		$cron = $container->getByType(Cron::class);

		try {
			$export = self::build($config, $catalog, $cron, $bootstrap, \getcwd() ?: '.');
		} catch (\InvalidArgumentException $e) {
			\fwrite(\STDERR, 'export-definitions: ' . $e->getMessage() . "\n");

			return self::FAILURE;
		}

		$json = Json::encode($export, Json::PRETTY) . "\n";
		$target = $input->getOption('output');

		if (!\is_string($target) || $target === '') {
			$output->write($json, false, OutputInterface::OUTPUT_RAW);

			return self::SUCCESS;
		}

		self::writeAtomically($target, $json);

		return self::SUCCESS;
	}

	/**
	 * A → B → A in `after` would enqueue the two crons after each other forever
	 * (dependent jobs bypass the queue limit).
	 * @param list<array<string, mixed>> $crons
	 * @throws \InvalidArgumentException
	 */
	private static function assertNoAfterCycle(array $crons): void
	{
		$after = [];

		foreach ($crons as $cron) {
			if (\is_string($cron['after'] ?? null) && \is_string($cron['code'] ?? null)) {
				$after[$cron['code']] = $cron['after'];
			}
		}

		foreach (\array_keys($after) as $start) {
			$seen = [$start => true];
			$current = $start;

			while (isset($after[$current])) {
				$current = $after[$current];

				if (isset($seen[$current])) {
					throw new \InvalidArgumentException(\sprintf('"after" dependencies form a cycle: %s.', \implode(' → ', \array_merge(\array_keys($seen), [$current]))));
				}

				$seen[$current] = true;
			}
		}
	}

	/**
	 * @param array<string, \LiquidMonitorConnector\Scheduler\CronDefinition> $definitions
	 * @param list<string> $warnings
	 * @return array<string, mixed>
	 */
	private static function cronEntry(CronDefinition $definition, CronDefinitionCatalog $catalog, array $definitions, array &$warnings): array
	{
		$attribute = $definition->attribute;
		$schedule = $definition->schedule();

		foreach ($attribute->allExpressions() as $expression) {
			if (!CronExpression::isValidExpression($expression)) {
				throw new \InvalidArgumentException(\sprintf('%s: invalid cron expression "%s".', $definition->code, $expression));
			}
		}

		$after = null;

		if ($attribute->after !== null) {
			$after = $catalog->codeOf($attribute->after);

			if ($after === null || !isset($definitions[$after])) {
				throw new \InvalidArgumentException(\sprintf('%s: "after" refers to %s, which is not a registered #[PullCron] handler.', $definition->code, $attribute->after));
			}

			if ($after === $definition->code) {
				throw new \InvalidArgumentException(\sprintf('%s: "after" cannot refer to the cron itself.', $definition->code));
			}
		}

		if ($attribute->timeout === 0) {
			$warnings[] = $definition->code . ': timeout 0 = no limit (LQDeck would read it as 1000 s)';
		}

		if ($attribute->repeatCount < 0 || $attribute->missedGraceMinutes < 0 || ($attribute->maxQueueSize !== null && $attribute->maxQueueSize < 1)) {
			throw new \InvalidArgumentException(\sprintf('%s: repeatCount, missedGraceMinutes and maxQueueSize must not be negative (maxQueueSize at least 1).', $definition->code));
		}

		return [
			'code' => $definition->code,
			'handler_class' => $definition->handlerClass,
			'name' => $attribute->name,
			'description' => $attribute->description,
			'schedule' => $schedule,
			'schedule_source' => $definition->scheduleSource(),
			'timeout' => $attribute->timeout,
			'repeat_count' => $attribute->repeatCount,
			'concurrency_mode' => $definition->concurrencyMode(),
			'max_queue_size' => $attribute->maxQueueSize,
			'resources' => $attribute->resources,
			'after' => $after,
			'missed' => $attribute->missed,
			'missed_grace_minutes' => $attribute->missedGraceMinutes,
		];
	}
}
