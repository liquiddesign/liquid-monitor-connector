<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

use LiquidMonitorConnector\HealthCheck\HealthCheckData;
use LiquidMonitorConnector\HealthCheck\HealthCheckStatusEnum;
use Nette\Utils\Json;

/**
 * Health of the local scheduler for the project's health action
 * (docs/v4-local-scheduler.md § 11.4). Reads SQLite directly and read-only —
 * no `proc_open`, which FPM often forbids — so it works from a web request.
 */
final class SchedulerHealth
{
	public const TICK_CRITICAL_SECONDS = 180;
	public const PAUSE_WARNING_SECONDS = 900;
	public const OUTBOX_WARNING_SECONDS = 3600;
	public const DELIVER_WARNING_SECONDS = 3600;
	public const MIN_FREE_BYTES = 524288000;

	public function __construct(private string $stateDir)
	{
	}

	/**
	 * @return list<\LiquidMonitorConnector\HealthCheck\HealthCheckData>
	 */
	public function checks(int|null $nowMs = null): array
	{
		$nowMs ??= Clock::nowMs();
		$checks = [];

		try {
			$connection = SchedulerDatabase::openReadOnly($this->stateDir);
		} catch (\Throwable $e) {
			return [new HealthCheckData('lqcron', $e->getMessage(), HealthCheckStatusEnum::CRITICAL, 'Local scheduler database is not readable.')];
		}

		try {
			$meta = [];

			foreach ($connection->fetchAll('SELECT key, value FROM meta') as $row) {
				$meta[(string) $row['key']] = (string) $row['value'];
			}

			$checks[] = $this->tickCheck($meta, $nowMs);
			$checks[] = $this->writableCheck();
			$checks[] = $this->refusedCheck($meta);
			$checks[] = $this->diskCheck();
			$checks[] = $this->pauseCheck($meta, $nowMs);
			$checks[] = $this->exportCheck($meta);
			$checks[] = $this->outboxCheck($connection, $nowMs);
			$checks[] = $this->deliverCheck($meta, $nowMs);
			$checks[] = $this->rejectedCheck($connection);
		} catch (\Throwable $e) {
			$checks[] = new HealthCheckData('lqcron', $e->getMessage(), HealthCheckStatusEnum::CRITICAL, 'Local scheduler database could not be read.');
		} finally {
			$connection->close();
		}

		return $checks;
	}

	/**
	 * @param array<string, string> $meta
	 */
	private function tickCheck(array $meta, int $nowMs): HealthCheckData
	{
		$last = isset($meta['last_tick_seen_at']) && \is_numeric($meta['last_tick_seen_at']) ? (int) $meta['last_tick_seen_at'] : null;

		if ($last === null) {
			return new HealthCheckData('lqcron.tick', null, HealthCheckStatusEnum::CRITICAL, 'lqcron tick has never run.');
		}

		$age = \intdiv($nowMs - $last, 1000);

		return new HealthCheckData(
			'lqcron.tick',
			Clock::iso($last),
			$age > self::TICK_CRITICAL_SECONDS ? HealthCheckStatusEnum::CRITICAL : HealthCheckStatusEnum::OK,
			$age > self::TICK_CRITICAL_SECONDS ? \sprintf('Last tick %d s ago — is the crontab line in place?', $age) : null,
		);
	}

	private function writableCheck(): HealthCheckData
	{
		$database = SchedulerDatabase::path($this->stateDir);
		$writable = \is_writable($this->stateDir) && \is_writable($database);

		return new HealthCheckData(
			'lqcron.stateDir',
			$this->stateDir,
			$writable ? HealthCheckStatusEnum::OK : HealthCheckStatusEnum::CRITICAL,
			$writable ? null : 'stateDir or the database is not writable for this user (WAL needs the directory writable too).',
		);
	}

	/**
	 * @param array<string, string> $meta
	 */
	private function refusedCheck(array $meta): HealthCheckData
	{
		$reason = $meta['tick_refused'] ?? '';

		return new HealthCheckData(
			'lqcron.refused',
			$reason === '' ? null : $reason,
			$reason === '' ? HealthCheckStatusEnum::OK : HealthCheckStatusEnum::CRITICAL,
			match ($reason) {
				'' => null,
				'schema' => 'Tick refuses to run: rollback across an incompatible schema migration.',
				'state_dir_changed' => 'Tick refuses to run: stateDir changed without `lqcron tick --move-state`.',
				'other_host' => 'Tick refuses to run: another host ran the scheduler on this stateDir less than 5 minutes ago.',
				default => 'Tick refuses to run: ' . $reason,
			},
		);
	}

	private function diskCheck(): HealthCheckData
	{
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- an unknown free space is reported as a WARNING below
		$free = @\disk_free_space($this->stateDir);

		if ($free === false) {
			return new HealthCheckData('lqcron.disk', null, HealthCheckStatusEnum::WARNING, 'Free space in stateDir is unknown.');
		}

		return new HealthCheckData(
			'lqcron.disk',
			(int) $free,
			$free < self::MIN_FREE_BYTES ? HealthCheckStatusEnum::CRITICAL : HealthCheckStatusEnum::OK,
			$free < self::MIN_FREE_BYTES ? 'Less than 500 MB free in stateDir; progress events are no longer stored.' : null,
		);
	}

	/**
	 * @param array<string, string> $meta
	 */
	private function pauseCheck(array $meta, int $nowMs): HealthCheckData
	{
		$since = null;

		foreach (['pause_deploy', 'pause_remote'] as $key) {
			$value = $meta[$key] ?? '';

			if ($value === '') {
				continue;
			}

			$pause = Json::decode($value, Json::FORCE_ARRAY);

			if (!\is_array($pause) || !isset($pause['since']) || !\is_numeric($pause['since'])) {
				continue;
			}

			$since = $since === null ? (int) $pause['since'] : \min($since, (int) $pause['since']);
		}

		if ($since === null) {
			return new HealthCheckData('lqcron.pause', false);
		}

		$long = $nowMs - $since > self::PAUSE_WARNING_SECONDS * 1000;

		return new HealthCheckData(
			'lqcron.pause',
			Clock::iso($since),
			$long ? HealthCheckStatusEnum::WARNING : HealthCheckStatusEnum::INFO,
			$long ? 'Scheduler paused for more than 15 minutes (`lqcron resume`).' : 'Scheduler paused.',
		);
	}

	/**
	 * @param array<string, string> $meta
	 */
	private function exportCheck(array $meta): HealthCheckData
	{
		$error = $meta['export_error'] ?? '';

		return new HealthCheckData(
			'lqcron.definitions',
			$meta['export_hash'] ?? null,
			$error === '' ? HealthCheckStatusEnum::OK : HealthCheckStatusEnum::WARNING,
			$error === '' ? null : 'Last export of cron definitions failed, the previous set is used: ' . $error,
		);
	}

	private function outboxCheck(SqliteConnection $connection, int $nowMs): HealthCheckData
	{
		$row = $connection->fetchOne('SELECT COUNT(*) AS size, MIN(created_at) AS oldest FROM outbox');
		$size = (int) ($row['size'] ?? 0);
		$oldest = isset($row['oldest']) && \is_numeric($row['oldest']) ? (int) $row['oldest'] : null;
		$stale = $oldest !== null && $nowMs - $oldest > self::OUTBOX_WARNING_SECONDS * 1000;

		return new HealthCheckData(
			'lqcron.outbox',
			$size,
			$stale ? HealthCheckStatusEnum::WARNING : HealthCheckStatusEnum::OK,
			$stale ? 'LQDeck has not accepted events for over an hour (cron runs are not affected).' : null,
		);
	}

	/**
	 * @param array<string, string> $meta
	 */
	private function deliverCheck(array $meta, int $nowMs): HealthCheckData
	{
		$last = isset($meta['deliver_last_ok_at']) && \is_numeric($meta['deliver_last_ok_at']) ? (int) $meta['deliver_last_ok_at'] : null;
		$stale = $last === null || $nowMs - $last > self::DELIVER_WARNING_SECONDS * 1000;

		return new HealthCheckData(
			'lqcron.deliver',
			$last === null ? null : Clock::iso($last),
			$stale ? HealthCheckStatusEnum::WARNING : HealthCheckStatusEnum::OK,
			$stale ? 'No successful connection to LQDeck for over an hour' . (isset($meta['deliver_last_error']) ? ': ' . $meta['deliver_last_error'] : '.') : null,
		);
	}

	private function rejectedCheck(SqliteConnection $connection): HealthCheckData
	{
		$rejected = (int) $connection->fetchValue('SELECT COUNT(*) FROM outbox_rejected');
		$poisoned = (int) $connection->fetchValue('SELECT COUNT(*) FROM outbox WHERE poisoned_at IS NOT NULL');
		$problem = $rejected > 0 || $poisoned > 0;

		return new HealthCheckData(
			'lqcron.rejected',
			['rejected' => $rejected, 'poisoned' => $poisoned],
			$problem ? HealthCheckStatusEnum::WARNING : HealthCheckStatusEnum::OK,
			$problem ? 'Some events were refused by LQDeck (`lqcron status`, `lqcron outbox retry-rejected`).' : null,
		);
	}
}
