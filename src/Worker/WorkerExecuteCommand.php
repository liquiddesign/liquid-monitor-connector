<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use LiquidMonitorConnector\Scheduler\LocalRunContext;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\Json;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'execute', description: 'Execute a single claimed cron job (child process).')]
final class WorkerExecuteCommand extends Command
{
	public const ENV_JOB_ID = 'MONITOR_JOB_ID';
	public const ENV_JOB_LOG_ID = 'MONITOR_JOB_LOG_ID';
	public const ENV_CRON_CODE = 'MONITOR_CRON_CODE';
	public const ENV_ARGUMENTS = 'MONITOR_ARGUMENTS';
	public const ENV_TIMEOUT = 'MONITOR_TIMEOUT';

	protected function configure(): void
	{
		$this
			->addOption('bootstrap', null, InputOption::VALUE_REQUIRED, 'PHP file returning a Nette DI Container')
			->addOption('job-id', null, InputOption::VALUE_REQUIRED, 'Monitor job ID')
			->addOption('job-log-id', null, InputOption::VALUE_REQUIRED, 'Monitor job log ID')
			->addOption('cron-code', null, InputOption::VALUE_REQUIRED, 'Cron code')
			->addOption('arguments', null, InputOption::VALUE_REQUIRED, 'JSON-encoded job arguments')
			->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Job timeout in seconds')
			->addOption('local', null, InputOption::VALUE_NONE, 'Run spawned by lqcron run-job (local scheduler)')
			->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'lqcron run uuid (with --local)')
			->addOption('arguments-file', null, InputOption::VALUE_REQUIRED, 'JSON file with job arguments (with --local)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		if ((bool) $input->getOption('local')) {
			return $this->executeLocal($input);
		}

		$bootstrap = $input->getOption('bootstrap');

		if (!\is_string($bootstrap) || $bootstrap === '') {
			\fwrite(\STDERR, "monitor-worker execute: --bootstrap is required\n");

			return self::FAILURE;
		}

		if (!\is_file($bootstrap)) {
			\fwrite(\STDERR, "monitor-worker execute: bootstrap file not found: {$bootstrap}\n");

			return self::FAILURE;
		}

		$jobId = $this->resolveIntOption($input, 'job-id', self::ENV_JOB_ID);
		$jobLogId = $this->resolveIntOption($input, 'job-log-id', self::ENV_JOB_LOG_ID);
		$cronCode = $this->resolveStringOption($input, 'cron-code', self::ENV_CRON_CODE);
		$argumentsJson = $this->resolveStringOption($input, 'arguments', self::ENV_ARGUMENTS);
		$timeout = $this->resolveIntOption($input, 'timeout', self::ENV_TIMEOUT);

		if ($jobId <= 0 || $jobLogId <= 0 || $cronCode === '') {
			\fwrite(\STDERR, "monitor-worker execute: job id, job log id and cron code are required\n");

			return self::FAILURE;
		}

		$arguments = null;

		if ($argumentsJson !== '') {
			try {
				$decoded = Json::decode($argumentsJson, Json::FORCE_ARRAY);
				$arguments = \is_array($decoded) ? $decoded : null;
			} catch (\Throwable $e) {
				\fwrite(\STDERR, 'monitor-worker execute: invalid arguments JSON: ' . $e->getMessage() . "\n");

				return self::FAILURE;
			}
		}

		if ($timeout > 0) {
			\set_time_limit($timeout);
		}

		try {
			/** @var mixed $container */
			$container = require $bootstrap;

			if (!$container instanceof Container) {
				throw new \RuntimeException('Bootstrap file must return a Nette\DI\Container instance.');
			}

			$registry = $container->getByType(CronJobHandlerRegistry::class);
			$handler = $registry->get($cronCode);
			$handler->execute($arguments);

			$output->writeln(Json::encode([
				'status' => 'ok',
				'jobId' => $jobId,
				'jobLogId' => $jobLogId,
				'cronCode' => $cronCode,
			]));

			return self::SUCCESS;
		} catch (\Throwable $e) {
			\fwrite(\STDERR, $e->getMessage());

			return self::FAILURE;
		}
	}

	/**
	 * Child of `lqcron run-job` (docs/v4-contract.md § 7.1). Moves the run to
	 * `running` itself, then runs the handler and leaves the end marker; the final
	 * state, retries and the report to LQDeck are lqcron's job.
	 */
	private function executeLocal(InputInterface $input): int
	{
		// Traces go to LQDeck and into triage tasks: no call arguments in them (§ 7.4).
		\ini_set('zend.exception_ignore_args', '1');

		$runId = $this->resolveStringOption($input, 'run-id', LocalRunContext::ENV_RUN_ID);
		$stateDir = \getenv(LocalRunContext::ENV_STATE_DIR);
		$cronCode = $this->resolveStringOption($input, 'cron-code', self::ENV_CRON_CODE);

		if ($runId === '' || !\is_string($stateDir) || $stateDir === '' || $cronCode === '') {
			\fwrite(\STDERR, "monitor-worker execute --local: --run-id, --cron-code and LQCRON_STATE_DIR are required\n");

			return self::FAILURE;
		}

		$context = new LocalRunContext($stateDir, $runId);

		try {
			$started = $context->markRunning(\realpath(\getcwd() ?: '.') ?: (\getcwd() ?: '.'));
		} catch (\Throwable $e) {
			// Schema too new or database unavailable: leave only the marker, a newer
			// tick settles the run (§ 12.1). The handler does not run.
			$context->writeMarker($e);
			\fwrite(\STDERR, 'monitor-worker execute --local: ' . $e->getMessage() . "\n");

			return self::FAILURE;
		}

		if (!$started) {
			\fwrite(\STDERR, "monitor-worker execute --local: run {$runId} is not in state starting (cancelled or reclaimed), handler not started\n");

			return 3;
		}

		LocalRunContext::activate($context);

		// Fatal errors bypass catch blocks; the marker still records the failure.
		\register_shutdown_function(static function () use ($context): void {
			if ($context->isMarkerWritten()) {
				return;
			}

			$error = \error_get_last();
			$fatal = $error !== null && Arrays::contains([\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_USER_ERROR], $error['type']) ? $error : null;
			$context->writeMarker(new \RuntimeException('PHP process ended without finishing the run' . ($fatal === null ? ' (exit() in the handler?)' : '')), $fatal);
		});

		try {
			$arguments = $this->readArgumentsFile($input);
			$timeout = (int) (\getenv(LocalRunContext::ENV_TIMEOUT) ?: 0);

			if ($timeout > 0) {
				// lqcron enforces the timeout on the whole process group; this only
				// stops PHP code a little earlier and with a readable error.
				\set_time_limit($timeout);
			}

			$bootstrap = $input->getOption('bootstrap');
			$container = ContainerBootstrap::load(\is_string($bootstrap) ? $bootstrap : null);
			$registry = $container->getByType(CronJobHandlerRegistry::class);
			$handler = $registry->get($cronCode);

			$this->installTerminationHandler($handler, $context);
			$handler->execute($arguments);
			$context->writeMarker();

			return self::SUCCESS;
		} catch (\Throwable $e) {
			$context->writeMarker($e);
			\fwrite(\STDERR, $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");

			return self::FAILURE;
		}
	}

	/**
	 * @return array<mixed>|null
	 */
	private function readArgumentsFile(InputInterface $input): array|null
	{
		$file = $input->getOption('arguments-file');

		if (!\is_string($file) || $file === '' || !\is_file($file)) {
			return null;
		}

		$decoded = Json::decode((string) \file_get_contents($file), Json::FORCE_ARRAY);

		return \is_array($decoded) ? $decoded : null;
	}

	private function installTerminationHandler(CronJobHandler $handler, LocalRunContext $context): void
	{
		if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
			return;
		}

		\pcntl_async_signals(true);
		\pcntl_signal(\SIGTERM, static function () use ($handler, $context): void {
			try {
				if ($handler instanceof InterruptibleCronJobHandler) {
					$handler->interrupt();
				}
			} finally {
				$context->writeMarker(new \RuntimeException('Terminated by SIGTERM (timeout or cancel).'));

				exit(143);
			}
		});
	}

	private function resolveStringOption(InputInterface $input, string $name, string $envName): string
	{
		$value = $input->getOption($name);

		if (\is_string($value) && $value !== '') {
			return $value;
		}

		$fromEnv = \getenv($envName);

		if ($fromEnv !== false && $fromEnv !== '') {
			return $fromEnv;
		}

		return '';
	}

	private function resolveIntOption(InputInterface $input, string $name, string $envName): int
	{
		$value = $this->resolveStringOption($input, $name, $envName);

		return $value !== '' ? (int) $value : 0;
	}
}
