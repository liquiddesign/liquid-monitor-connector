<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

use LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException;
use Nette\Utils\Arrays;

/**
 * Minimal SQLite access over `pdo_sqlite` (preferred) or the `sqlite3` extension.
 * Servers ship either of the two independently, and the scheduler database only
 * needs plain parameterised statements and explicit `BEGIN IMMEDIATE` transactions.
 *
 * PHP never creates the database — only `lqcron` does (docs/v4-local-scheduler.md § 4.1).
 */
final class SqliteConnection
{
	public const MIN_SQLITE_VERSION = '3.24.0';

	private function __construct(private \PDO|null $pdo, private \SQLite3|null $sqlite)
	{
	}

	/**
	 * @throws \LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException
	 */
	public static function open(string $file, bool $readOnly, int $busyTimeoutMs, string|null $forceDriver = null): self
	{
		// Checked before opening: opening a missing file would create an empty
		// database nobody reads (a web node without the scheduler).
		if (!\is_file($file)) {
			throw new SchedulerUnavailableException(\sprintf('Local scheduler database "%s" does not exist (is lqcron running on this host?).', $file));
		}

		$driver = $forceDriver ?? self::configuredDriver() ?? self::availableDriver();

		try {
			if ($driver === 'pdo_sqlite') {
				$options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC];
				$pdo = new \PDO('sqlite:' . $file, null, null, $options);
				$pdo->exec('PRAGMA busy_timeout = ' . $busyTimeoutMs);

				// Read-only open flags differ across PHP 8.0–8.5 (PDO::SQLITE_* deprecated
				// in 8.5, Pdo\Sqlite only since 8.4); query_only works everywhere, and the
				// file is known to exist, so nothing gets created.
				if ($readOnly) {
					$pdo->exec('PRAGMA query_only = 1');
				}

				return new self($pdo, null);
			}

			if ($driver === 'sqlite3') {
				$sqlite = new \SQLite3($file, $readOnly ? \SQLITE3_OPEN_READONLY : \SQLITE3_OPEN_READWRITE);
				$sqlite->enableExceptions(true);
				$sqlite->busyTimeout($busyTimeoutMs);

				return new self(null, $sqlite);
			}
		} catch (\Throwable $e) {
			throw new SchedulerUnavailableException(\sprintf('Cannot open local scheduler database "%s": %s', $file, $e->getMessage()), 0, $e);
		}

		throw new SchedulerUnavailableException('Neither pdo_sqlite nor sqlite3 PHP extension is available; the local scheduler needs one of them.');
	}

	/**
	 * @param array<int|string, scalar|null> $params
	 * @return list<array<string, mixed>>
	 */
	public function fetchAll(string $sql, array $params = []): array
	{
		if ($this->pdo !== null) {
			$statement = $this->pdo->prepare($sql);
			$statement->execute(self::positional($params));

			/** @var list<array<string, mixed>> $rows */
			$rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

			return $rows;
		}

		$result = $this->sqliteStatement($sql, $params)->execute();
		$rows = [];

		if ($result === false) {
			return $rows;
		}

		while (($row = $result->fetchArray(\SQLITE3_ASSOC)) !== false) {
			/** @var array<string, mixed> $row */
			$rows[] = $row;
		}

		$result->finalize();

		return $rows;
	}

	/**
	 * @param array<int|string, scalar|null> $params
	 * @return array<string, mixed>|null
	 */
	public function fetchOne(string $sql, array $params = []): array|null
	{
		return $this->fetchAll($sql, $params)[0] ?? null;
	}

	/**
	 * @param array<int|string, scalar|null> $params
	 */
	public function fetchValue(string $sql, array $params = []): mixed
	{
		$row = $this->fetchOne($sql, $params);

		return $row === null ? null : \reset($row);
	}

	/**
	 * Runs a write statement and returns the number of changed rows.
	 * @param array<int|string, scalar|null> $params
	 */
	public function execute(string $sql, array $params = []): int
	{
		if ($this->pdo !== null) {
			$statement = $this->pdo->prepare($sql);
			$statement->execute(self::positional($params));

			return $statement->rowCount();
		}

		\assert($this->sqlite !== null);
		$this->sqliteStatement($sql, $params)->execute();

		return $this->sqlite->changes();
	}

	/**
	 * Runs `$callback` inside `BEGIN IMMEDIATE`: the write lock is taken up front,
	 * so a busy database waits for `busy_timeout` instead of failing on the lock
	 * upgrade of a deferred transaction.
	 * @template T
	 * @param callable(self): T $callback
	 * @return T
	 */
	public function transaction(callable $callback): mixed
	{
		$this->rawExec('BEGIN IMMEDIATE');

		try {
			$result = $callback($this);
			$this->rawExec('COMMIT');

			return $result;
		} catch (\Throwable $e) {
			try {
				$this->rawExec('ROLLBACK');
			} catch (\Throwable) {
				// The original error is the one worth reporting.
			}

			throw $e;
		}
	}

	public function close(): void
	{
		$this->sqlite?->close();
	}

	/**
	 * `LQCRON_PHP_SQLITE_DRIVER=sqlite3|pdo_sqlite` pins the driver — CI runs the
	 * PHP side with each, since servers ship either of the two.
	 */
	public static function configuredDriver(): string|null
	{
		$driver = \getenv('LQCRON_PHP_SQLITE_DRIVER');

		return $driver === 'sqlite3' || $driver === 'pdo_sqlite' ? $driver : null;
	}

	public static function availableDriver(): string|null
	{
		// The PDO driver may be compiled in without `extension_loaded('pdo_sqlite')`.
		if (\class_exists(\PDO::class) && Arrays::contains(\PDO::getAvailableDrivers(), 'sqlite')) {
			return 'pdo_sqlite';
		}

		if (\extension_loaded('sqlite3')) {
			return 'sqlite3';
		}

		return null;
	}

	/**
	 * Version of the SQLite library PHP links against (the system one — unlike
	 * the copy bundled into lqcron), or null without a driver.
	 */
	public static function libraryVersion(): string|null
	{
		$driver = self::availableDriver();

		if ($driver === 'pdo_sqlite') {
			$pdo = new \PDO('sqlite::memory:');
			$statement = $pdo->query('SELECT sqlite_version()');
			$version = $statement === false ? null : $statement->fetchColumn();

			return \is_string($version) ? $version : null;
		}

		if ($driver === 'sqlite3') {
			$version = \SQLite3::version()['versionString'];

			return \is_string($version) ? $version : null;
		}

		return null;
	}

	private function rawExec(string $sql): void
	{
		if ($this->pdo !== null) {
			$this->pdo->exec($sql);

			return;
		}

		\assert($this->sqlite !== null);
		$this->sqlite->exec($sql);
	}

	/**
	 * @param array<int|string, scalar|null> $params
	 */
	private function sqliteStatement(string $sql, array $params): \SQLite3Stmt
	{
		\assert($this->sqlite !== null);
		$statement = $this->sqlite->prepare($sql);

		if ($statement === false) {
			throw new \RuntimeException('Cannot prepare SQLite statement: ' . $this->sqlite->lastErrorMsg());
		}

		foreach (self::positional($params) as $index => $value) {
			$type = match (true) {
				$value === null => \SQLITE3_NULL,
				\is_int($value), \is_bool($value) => \SQLITE3_INTEGER,
				\is_float($value) => \SQLITE3_FLOAT,
				default => \SQLITE3_TEXT,
			};
			$statement->bindValue($index + 1, \is_bool($value) ? (int) $value : $value, $type);
		}

		return $statement;
	}

	/**
	 * @param array<int|string, scalar|null> $params
	 * @return list<scalar|null>
	 */
	private static function positional(array $params): array
	{
		return \array_values(\array_map(static fn ($value) => \is_bool($value) ? (int) $value : $value, $params));
	}
}
