<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'run', description: 'Claim cron jobs from Liquid Monitor and execute handlers locally.')]
final class WorkerRunCommand extends Command
{
	public function __construct(private readonly WorkerClient $client, private readonly string $workerScript,)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->addOption('bootstrap', null, InputOption::VALUE_REQUIRED, 'PHP file returning a Nette DI Container')
			->addOption('monitor-url', null, InputOption::VALUE_REQUIRED, 'Liquid Monitor /api/connector base URL')
			->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Project API key')
			->addOption('max-parallel', null, InputOption::VALUE_REQUIRED, 'Maximum parallel child jobs', '4')
			->addOption('poll-interval', null, InputOption::VALUE_REQUIRED, 'Default poll interval when monitor does not suggest one', '15')
			->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Maximum parent runtime in seconds', '55')
			->addOption('lease-seconds', null, InputOption::VALUE_REQUIRED, 'Lease duration for claimed jobs', '60')
			->addOption('once', null, InputOption::VALUE_NONE, 'Run a single poll cycle (for tests)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$bootstrap = $input->getOption('bootstrap');

		if (!\is_string($bootstrap) || $bootstrap === '') {
			$output->writeln('<error>--bootstrap is required.</error>');

			return self::FAILURE;
		}

		if (!\is_file($bootstrap)) {
			$output->writeln('<error>Bootstrap file not found: ' . $bootstrap . '</error>');

			return self::FAILURE;
		}

		$workerId = $this->buildWorkerId();
		$lock = new WorkerLock($workerId);

		if (!$lock->acquire()) {
			$output->writeln('<info>Another monitor-worker run is still active — skipping.</info>');

			return self::SUCCESS;
		}

		try {
			$loop = new WorkerRunLoop(
				client: $this->client,
				workerId: $workerId,
				maxParallel: (int) $input->getOption('max-parallel'),
				pollInterval: (int) $input->getOption('poll-interval'),
				maxRuntime: (int) $input->getOption('max-runtime'),
				leaseSeconds: (int) $input->getOption('lease-seconds'),
				once: (bool) $input->getOption('once'),
				spawnChild: fn (ClaimedJob $job): Process => $this->spawnChildProcess($bootstrap, $job),
				output: $output,
			);

			return $loop->run() === 0 ? self::SUCCESS : self::FAILURE;
		} finally {
			$lock->release();
		}
	}

	private function buildWorkerId(): string
	{
		$hostname = \gethostname();

		return ($hostname !== false ? $hostname : 'worker') . '#' . \getmypid();
	}

	private function spawnChildProcess(string $bootstrap, ClaimedJob $job): Process
	{
		$phpBinary = (new PhpExecutableFinder())->find(false) ?: 'php';

		$command = [
			$phpBinary,
			$this->workerScript,
			'execute',
			'--bootstrap=' . $bootstrap,
			'--job-id=' . $job->jobId,
			'--job-log-id=' . $job->jobLogId,
			'--cron-code=' . $job->cronCode,
			'--timeout=' . $job->timeout,
		];

		if ($job->arguments !== null) {
			$command[] = '--arguments=' . \json_encode($job->arguments, \JSON_THROW_ON_ERROR);
		}

		return new Process(
			$command,
			cwd: \getcwd() ?: null,
			env: [
				WorkerExecuteCommand::ENV_JOB_ID => (string) $job->jobId,
				WorkerExecuteCommand::ENV_JOB_LOG_ID => (string) $job->jobLogId,
				WorkerExecuteCommand::ENV_CRON_CODE => $job->cronCode,
				WorkerExecuteCommand::ENV_ARGUMENTS => $job->arguments !== null ? \json_encode($job->arguments, \JSON_THROW_ON_ERROR) : '',
				WorkerExecuteCommand::ENV_TIMEOUT => (string) $job->timeout,
			],
		);
	}
}
