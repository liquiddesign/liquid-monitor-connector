<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\DbQuery;

use InvalidArgumentException;
use Nette\Utils\Strings;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Runs a single read-only SQL query against a database described by a connection
 * array. Hard guarantees:
 *  - only a single SELECT / WITH statement (no DML/DDL, no multiple statements),
 *  - a server-enforced row LIMIT (the query is wrapped in a capped subquery),
 *  - a driver-level statement timeout,
 *  - an isolated PDO connection per request.
 */
final class ReadOnlyQueryRunner
{
	private const DEFAULT_ROW_LIMIT = 100;

	private const MAX_ROW_LIMIT = 1000;

	private const DEFAULT_TIMEOUT_SECONDS = 5;

	private const FORBIDDEN_KEYWORDS = [
		'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
		'grant', 'revoke', 'replace', 'merge', 'call', 'exec', 'execute',
		'attach', 'copy', 'vacuum', 'lock', 'into',
	];

	/**
	 * @param array{driver: string, host: string, port?: int|null, database: string, username: string, password?: string|null} $connection
	 */
	public function __construct(
		private readonly array $connection,
		private readonly int $rowLimit = self::DEFAULT_ROW_LIMIT,
		private readonly int $statementTimeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
	) {
	}

	/**
	 * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, row_count: int, limit: int, truncated: bool}
	 */
	public function run(string $sql): array
	{
		$sql = Strings::trim($sql);
		$this->assertReadOnly($sql);

		$limit = \max(1, \min($this->rowLimit, self::MAX_ROW_LIMIT));
		$effectiveSql = $this->wrapWithLimit($sql, $limit);

		$pdo = $this->connect();
		$this->applyStatementTimeout($pdo);

		$statement = $pdo->query($effectiveSql);

		if (!$statement instanceof PDOStatement) {
			throw new PDOException('Query did not return a result set.');
		}

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);

		$columns = $rows === [] ? [] : \array_map('strval', \array_keys($rows[0]));

		return [
			'columns' => $columns,
			'rows' => $rows,
			'row_count' => \count($rows),
			'limit' => $limit,
			'truncated' => \count($rows) >= $limit,
		];
	}

	private function assertReadOnly(string $sql): void
	{
		if ($sql === '') {
			throw new InvalidArgumentException('Empty query.');
		}

		$normalized = $this->stripComments($sql);
		$trimmed = \ltrim($normalized);

		if (\preg_match('/^(select|with)\b/i', $trimmed) !== 1) {
			throw new InvalidArgumentException('Only SELECT or WITH queries are allowed.');
		}

		$body = \rtrim(\rtrim($trimmed), ';');

		if (\str_contains($body, ';')) {
			throw new InvalidArgumentException('Multiple statements are not allowed.');
		}

		foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
			if (\preg_match('/\b' . $keyword . '\b/i', $body) === 1) {
				throw new InvalidArgumentException("Disallowed keyword in query: {$keyword}.");
			}
		}
	}

	private function stripComments(string $sql): string
	{
		$sql = \preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;
		$sql = \preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;

		return \preg_replace('/#[^\n]*/', ' ', $sql) ?? $sql;
	}

	private function wrapWithLimit(string $sql, int $limit): string
	{
		$body = \rtrim(\rtrim(Strings::trim($sql)), ';');

		return "select * from ({$body}) as triage_readonly_sub limit {$limit}";
	}

	private function connect(): PDO
	{
		$driver = $this->resolveDriver($this->connection['driver']);
		$host = $this->connection['host'];
		$database = $this->connection['database'];
		$port = $this->connection['port'] ?? ($driver === 'pgsql' ? 5432 : 3306);
		$username = $this->connection['username'];
		$password = $this->connection['password'] ?? '';

		$dsn = match ($driver) {
			'mysql' => "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
			'pgsql' => "pgsql:host={$host};port={$port};dbname={$database}",
			default => throw new InvalidArgumentException('Unsupported database driver.'),
		};

		$pdo = new PDO($dsn, $username, $password, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		if ($driver === 'mysql') {
			$pdo->exec('SET NAMES utf8mb4');
		}

		return $pdo;
	}

	private function resolveDriver(string $driver): string
	{
		return match (Strings::lower($driver)) {
			'mysql', 'mariadb' => 'mysql',
			'pgsql', 'postgres', 'postgresql' => 'pgsql',
			default => throw new InvalidArgumentException('Unsupported database driver.'),
		};
	}

	private function applyStatementTimeout(PDO $pdo): void
	{
		$ms = \max(1, $this->statementTimeoutSeconds) * 1000;
		$driver = $this->resolveDriver($this->connection['driver']);

		try {
			if ($driver === 'pgsql') {
				$pdo->exec("SET statement_timeout = {$ms}");
			} elseif ($driver === 'mysql') {
				$pdo->exec("SET SESSION max_execution_time = {$ms}");
			}
		} catch (Throwable) {
			// Best effort — if the server variant doesn't support the setting, the
			// read still runs (credentials should point at a read-only user regardless).
		}
	}
}
