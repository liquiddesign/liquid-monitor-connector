<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use LiquidMonitorConnector\Cron;
use LiquidMonitorConnector\Scheduler\CronDefinitionCatalog;
use LiquidMonitorConnector\Scheduler\SchedulerConfig;
use Nette\Utils\FileSystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * Enqueues every cron whose `#[PullCron]` schedule is due in the current minute
 * (docs/v4-local-scheduler.md § 5.0). Replaces per-cron crunz tasks when the
 * local scheduler is off; run it every minute from crontab:
 *
 *     * * * * * cd /srv/app/current && php vendor/bin/monitor-worker enqueue-due
 *
 * The minute is captured when the process starts — before the container boots —
 * so a slow boot or a slow `schedule-job` cannot push it into the next minute.
 * Overlapping invocations queue on a lock for up to 50 s, each then evaluating
 * its own minute: nothing is skipped or doubled.
 */
final class EnqueueDueCommand extends Command
{
	public const NAME = 'enqueue-due';

	public const DEFAULT_LOCK_FILE = 'temp/lqcron/enqueue-due.lock';

	public const DEFAULT_LOCK_WAIT_SECONDS = 50;

	/**
	 * @param \Closure(string|null): \Nette\DI\Container|null $containerFactory
	 */
	public function __construct(private int $startedAt, private \Closure|null $containerFactory = null)
	{
		parent::__construct(self::NAME);
	}

	protected function configure(): void
	{
		$this
			->setDescription('Enqueue crons whose #[PullCron] schedule is due this minute (without lqcron).')
			->addOption('bootstrap', null, InputOption::VALUE_REQUIRED, 'PHP file returning a Nette DI Container (default: the package bootstrap)')
			->addOption('minute', null, InputOption::VALUE_REQUIRED, 'Evaluate this minute instead of the start time (Y-m-d\TH:i, scheduler timezone)')
			->addOption('lock-file', null, InputOption::VALUE_REQUIRED, 'Lock file relative to the working directory', self::DEFAULT_LOCK_FILE)
			->addOption('lock-wait', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for a previous run', (string) self::DEFAULT_LOCK_WAIT_SECONDS)
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be enqueued');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$lockFile = (string) $input->getOption('lock-file');
		$lock = $this->acquireLock($lockFile, (int) $input->getOption('lock-wait'));

		if ($lock === null) {
			$message = \sprintf(
				'enqueue-due: lock %s not acquired within %d s, minute %s skipped.',
				$lockFile,
				(int) $input->getOption('lock-wait'),
				(new \DateTimeImmutable('@' . $this->startedAt))->format('Y-m-d H:i e'),
			);
			$output->writeln('<error>' . $message . '</error>');
			self::log($message, ILogger::WARNING);

			return self::FAILURE;
		}

		try {
			$bootstrap = $input->getOption('bootstrap');
			$container = $this->containerFactory !== null
				? ($this->containerFactory)(\is_string($bootstrap) ? $bootstrap : null)
				: ContainerBootstrap::load(\is_string($bootstrap) ? $bootstrap : null);

			$config = ContainerBootstrap::service($container, SchedulerConfig::class);
			$catalog = ContainerBootstrap::service($container, CronDefinitionCatalog::class);
			$cron = ContainerBootstrap::service($container, Cron::class);

			if ($config->isLocal()) {
				$output->writeln('enqueue-due: scheduler.enabled is on — lqcron plans the crons itself, nothing to do. Remove enqueue-due from crontab.');

				return self::SUCCESS;
			}

			$minuteOption = $input->getOption('minute');
			$minute = $this->minute(\is_string($minuteOption) ? $minuteOption : null, $config->timezone());
			$dryRun = (bool) $input->getOption('dry-run');

			return $this->enqueueDue($catalog, $cron, $minute, $dryRun, $output);
		} finally {
			\flock($lock, \LOCK_UN);
			\fclose($lock);
		}
	}

	private function enqueueDue(CronDefinitionCatalog $catalog, Cron $cron, \DateTimeImmutable $minute, bool $dryRun, OutputInterface $output): int
	{
		$failures = 0;
		$enqueued = 0;

		foreach ($catalog->all() as $definition) {
			try {
				if (!$definition->isDue($minute)) {
					continue;
				}

				if (!$dryRun) {
					$cron->schedulePullJob($definition->handlerClass);
				}

				$enqueued++;
				$output->writeln(\sprintf('%s %s (%s)', $dryRun ? 'would enqueue' : 'enqueued', $definition->code, $definition->schedule()));
			} catch (\Throwable $e) {
				// One cron failing (full queue, LQDeck down, bad expression) must not stop the others.
				$failures++;
				$message = \sprintf('enqueue-due: %s failed for minute %s: %s', $definition->code, $minute->format('Y-m-d H:i'), $e->getMessage());
				$output->writeln('<error>' . $message . '</error>');
				self::log($message, ILogger::ERROR);
			}
		}

		$output->writeln(\sprintf('enqueue-due %s: %d enqueued, %d failed.', $minute->format('Y-m-d H:i T'), $enqueued, $failures), OutputInterface::VERBOSITY_VERBOSE);

		return $failures === 0 ? self::SUCCESS : self::FAILURE;
	}

	private function minute(string|null $minuteOption, \DateTimeZone $timezone): \DateTimeImmutable
	{
		if ($minuteOption !== null) {
			$minute = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $minuteOption, $timezone);

			if ($minute === false) {
				throw new \InvalidArgumentException('--minute must be in Y-m-d\TH:i format.');
			}
		} else {
			$minute = (new \DateTimeImmutable('@' . $this->startedAt))->setTimezone($timezone);
		}

		return $minute->setTime((int) $minute->format('H'), (int) $minute->format('i'), 0);
	}

	/**
	 * @return resource|null
	 */
	private function acquireLock(string $lockFile, int $waitSeconds)
	{
		$directory = \dirname($lockFile);

		FileSystem::createDir($directory, 0775);

		$handle = \fopen($lockFile, 'c');

		if ($handle === false) {
			throw new \RuntimeException("enqueue-due: cannot open lock file {$lockFile}");
		}

		$deadline = \microtime(true) + \max(0, $waitSeconds);

		do {
			if (\flock($handle, \LOCK_EX | \LOCK_NB)) {
				return $handle;
			}

			\usleep(200000);
		} while (\microtime(true) < $deadline);

		\fclose($handle);

		return null;
	}

	private static function log(string $message, string $level): void
	{
		try {
			Debugger::log($message, $level);
		} catch (\Throwable) {
			// Tracy may be unconfigured in CLI; the message is on stderr already.
		}
	}
}
