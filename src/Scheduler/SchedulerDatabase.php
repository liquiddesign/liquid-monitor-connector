<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

use LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException;

/**
 * Opens `stateDir/lqcron.sqlite` for PHP. The schema belongs to `lqcron`
 * (docs/v4-contract.md § 2); PHP only checks it may write into it (§ 12.1).
 */
final class SchedulerDatabase
{
	/** Schema version this PHP code was written against. */
	public const KNOWN_SCHEMA_VERSION = 1;

	public const FILE_NAME = 'lqcron.sqlite';

	/** Busy timeout from a web request: a request must not wait long for the lock (§ 9). */
	public const BUSY_TIMEOUT_WEB_MS = 1000;

	public const BUSY_TIMEOUT_CLI_MS = 5000;

	public static function path(string $stateDir): string
	{
		return \rtrim($stateDir, '/') . '/' . self::FILE_NAME;
	}

	/**
	 * @throws \LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException
	 */
	public static function openForWrite(string $stateDir): SqliteConnection
	{
		$connection = SqliteConnection::open(self::path($stateDir), false, self::busyTimeoutMs());
		self::assertCompatible($connection);

		return $connection;
	}

	/**
	 * @throws \LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException
	 */
	public static function openReadOnly(string $stateDir): SqliteConnection
	{
		return SqliteConnection::open(self::path($stateDir), true, self::busyTimeoutMs());
	}

	public static function busyTimeoutMs(): int
	{
		return \PHP_SAPI === 'cli' ? self::BUSY_TIMEOUT_CLI_MS : self::BUSY_TIMEOUT_WEB_MS;
	}

	/**
	 * @throws \LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException
	 */
	public static function assertCompatible(SqliteConnection $connection): void
	{
		try {
			$minReader = $connection->fetchValue("SELECT value FROM meta WHERE key = 'min_reader_version'");
		} catch (\Throwable $e) {
			throw new SchedulerUnavailableException('Local scheduler database is not initialised yet (run `lqcron tick` first): ' . $e->getMessage(), 0, $e);
		}

		if (!\is_numeric($minReader)) {
			throw new SchedulerUnavailableException('Local scheduler database has no schema version yet (run `lqcron tick` first).');
		}

		if ((int) $minReader > self::KNOWN_SCHEMA_VERSION) {
			throw new SchedulerUnavailableException(\sprintf(
				'Local scheduler database requires schema reader version %d, this connector knows %d (rolled back across an incompatible migration?).',
				(int) $minReader,
				self::KNOWN_SCHEMA_VERSION,
			));
		}
	}
}
