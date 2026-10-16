<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use LiquidMonitorConnector\Scheduler\LogRedactor;
use LiquidMonitorConnector\Scheduler\SchedulerConfig;
use Nette\DI\Container;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Filters the tail of a failed run's log through `scheduler.logRedactor`
 * (docs/v4-contract.md § 7.2): stdin in, redacted text out. `lqcron` calls it
 * before the tail goes into the outbox; any failure withholds the tail.
 */
final class RedactLogCommand extends Command
{
	public const NAME = 'redact-log';

	/**
	 * @param \Closure(string|null): \Nette\DI\Container|null $containerFactory
	 * @param resource|null $input
	 */
	public function __construct(private \Closure|null $containerFactory = null, private mixed $input = null)
	{
		parent::__construct(self::NAME);
	}

	public static function redactor(Container $container): LogRedactor|null
	{
		$config = $container->getByType(SchedulerConfig::class);

		if ($config->logRedactor === null) {
			return null;
		}

		$reference = \ltrim($config->logRedactor, '@');

		if ($container->hasService($reference)) {
			$service = $container->getService($reference);
		} elseif (\class_exists($reference) || \interface_exists($reference)) {
			$service = $container->getByType($reference);
		} else {
			throw new \RuntimeException(\sprintf('scheduler.logRedactor "%s" is neither a service nor a class.', $config->logRedactor));
		}

		if (!$service instanceof LogRedactor) {
			throw new \RuntimeException(\sprintf('scheduler.logRedactor "%s" must implement %s.', $config->logRedactor, LogRedactor::class));
		}

		return $service;
	}

	protected function configure(): void
	{
		$this
			->setDescription('Redact a log tail (stdin) with scheduler.logRedactor.')
			->addOption('bootstrap', null, InputOption::VALUE_REQUIRED, 'PHP file returning a Nette DI Container (default: the package bootstrap)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$bootstrap = $input->getOption('bootstrap');
		$container = $this->containerFactory !== null
			? ($this->containerFactory)(\is_string($bootstrap) ? $bootstrap : null)
			: ContainerBootstrap::load(\is_string($bootstrap) ? $bootstrap : null);

		$redactor = self::redactor($container);
		$log = \stream_get_contents($this->input ?? \STDIN);

		if ($log === false) {
			\fwrite(\STDERR, "redact-log: cannot read stdin\n");

			return self::FAILURE;
		}

		$output->write($redactor !== null ? $redactor->redact($log) : $log, false, OutputInterface::OUTPUT_RAW);

		return self::SUCCESS;
	}
}
