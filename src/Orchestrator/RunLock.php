<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\FileSystem;
use Nette\Utils\Strings;

/**
 * Single-instance guard for orchestrator runs (cron fires every minute).
 *
 * Deliberately NOT a held flock: a lock fd held for the whole run is inherited
 * by the spawned tmux server, which then keeps the lock for as long as any agent
 * session lives. Instead, flock only guards the atomic check-and-write of a PID
 * file; liveness of the recorded PID is what actually serializes runs.
 */
final class RunLock
{
	public function __construct(private readonly string $workerId)
	{
	}

	/**
	 * @return bool True when this process may run; false when another run is active.
	 */
	public function acquire(): bool
	{
		$handle = \fopen($this->path(), 'c+');

		if ($handle === false) {
			return true;
		}

		try {
			if (!\flock($handle, \LOCK_EX | \LOCK_NB)) {
				return false;
			}

			$previousPid = (int) Strings::trim((string) \stream_get_contents($handle));

			if ($previousPid > 0 && $this->isAlive($previousPid)) {
				\flock($handle, \LOCK_UN);

				return false;
			}

			\ftruncate($handle, 0);
			\rewind($handle);
			\fwrite($handle, (string) \getmypid());
			\fflush($handle);
			\flock($handle, \LOCK_UN);

			return true;
		} finally {
			\fclose($handle);
		}
	}

	public function release(): void
	{
		$file = $this->path();

		if (!\is_file($file)) {
			return;
		}

		if ((int) Strings::trim((string) \file_get_contents($file)) !== \getmypid()) {
			return;
		}

		FileSystem::delete($file);
	}

	private function isAlive(int $pid): bool
	{
		if ($pid === \getmypid()) {
			return true;
		}

		if (\function_exists('posix_kill')) {
			return \posix_kill($pid, 0);
		}

		return \is_dir('/proc/' . $pid);
	}

	private function path(): string
	{
		$slug = Strings::webalize($this->workerId);

		return \sys_get_temp_dir() . '/orchestrator-run-' . ($slug === '' ? 'default' : $slug) . '.pid';
	}
}
