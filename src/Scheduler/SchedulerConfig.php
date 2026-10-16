<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

/**
 * `liquidMonitorConnector.scheduler` from NEON (docs/v4-local-scheduler.md § 4).
 */
final class SchedulerConfig
{
	public const DEFAULT_REMOTE_COMMANDS = ['cron.set_active', 'cron.run_now', 'run.cancel'];

	public const SERVICE_MODES = ['watchdog', 'keepalive'];

	/**
	 * @param list<string> $remoteCommands
	 * @param array<string, int> $resources
	 * @param array<string, array{command: list<string>, mode: string}> $services
	 */
	public function __construct(
		public bool $enabled = false,
		public bool $shadow = false,
		public string|null $environment = null,
		public string|null $stateDir = null,
		public string $timezone = 'UTC',
		public int $maxParallel = 3,
		public int $starvationSeconds = 300,
		public int $runLogMaxBytes = 52428800,
		public int $killGraceSeconds = 30,
		public bool $reportLogTail = true,
		public string|null $logRedactor = null,
		public array $remoteCommands = self::DEFAULT_REMOTE_COMMANDS,
		public array $resources = [],
		public string|null $phpBinary = null,
		public string|null $bootstrap = null,
		public array $services = [],
	) {
	}

	/**
	 * Jobs go into the local queue (and `lqcron` runs them) only with the scheduler
	 * on and outside shadow mode; in shadow mode v3 still runs everything (§ 13.2).
	 */
	public function isLocal(): bool
	{
		return $this->enabled && !$this->shadow;
	}

	/**
	 * Whether `lqcron` runs at all (local or shadow) and so needs `stateDir`.
	 */
	public function isSchedulerRunning(): bool
	{
		return $this->enabled || $this->shadow;
	}

	public function timezone(): \DateTimeZone
	{
		return new \DateTimeZone($this->timezone);
	}
}
