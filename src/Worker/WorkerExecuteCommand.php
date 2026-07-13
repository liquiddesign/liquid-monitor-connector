<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Nette\DI\Container;
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
			->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Job timeout in seconds');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
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
				$decoded = Json::decode($argumentsJson, forceArrays: true);
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
