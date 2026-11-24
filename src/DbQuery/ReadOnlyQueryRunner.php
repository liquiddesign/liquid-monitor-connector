<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\DbQuery;

// phpcs:disable Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- the SQL lexer works on byte offsets (strlen/strpos), not on characters

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
 *
 * The keyword check alone is not a boundary: it inspects a copy of the SQL, while the
 * database runs the original text. Until 3.2.5 the copy dropped `#…` / `--…` even
 * inside string literals and ignored MySQL executable comments (`/*! … *\/`), so
 * `select '#' ) as t; drop table x; …` passed the check and ran as several statements
 * under the caller's credentials. The check now masks literals and comments the way
 * the server lexes them and refuses executable comments, and the connection itself
 * refuses more than one statement (MySQL `MULTI_STATEMENTS` off, PostgreSQL native
 * prepare) and runs in a read-only session — a write fails in the database even if
 * a query slips past the check.
 */
final class ReadOnlyQueryRunner
{
	private const DEFAULT_ROW_LIMIT = 100;

	private const MAX_ROW_LIMIT = 1000;

	private const DEFAULT_TIMEOUT_SECONDS = 5;

	/**
	 * Characters stripped by ltrim()/rtrim() — the pre-PHP 8.6 default, spelled out so the
	 * behaviour doesn't change when 8.6 adds the form feed to the default set.
	 */
	private const TRIM_CHARACTERS = " \n\r\t\v\0";

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
		$this->applyReadOnlySession($pdo);
		$this->applyStatementTimeout($pdo);

		// PostgreSQL `query()` runs the simple protocol, which accepts several statements in one
		// string; a prepared statement (extended protocol) accepts exactly one. MySQL refuses
		// several statements already on the connection (MULTI_STATEMENTS off, see connect()).
		$statement = $this->resolveDriver($this->connection['driver']) === 'mysql'
			? $pdo->query($effectiveSql)
			: $this->executePrepared($pdo, $effectiveSql);

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

	/**
	 * Copy of $sql for the keyword check: every string literal and quoted identifier becomes `q`,
	 * every comment a space — lexed the way the server lexes them, so a `#` or `;` inside a literal
	 * neither hides nor fakes a statement. Where the lexers of the two servers differ, it masks less
	 * than the server would (a stricter check), never more:
	 *  - MySQL: `#` and `-- ` (dash dash + whitespace) are comments; `--x` is not; backslash escapes
	 *    a quote (default sql_mode);
	 *  - PostgreSQL: `--` is always a comment, `#` is an operator, backslash escapes only in `E'…'`;
	 *    dollar-quoted strings (`$$…$$`, `$tag$…$tag$`) are literals too — left unmasked, a quote
	 *    inside one would open a literal the server never sees and hide the code after it.
	 *
	 * Refuses MySQL / MariaDB executable comments (`/*! … *\/`, `/*M! … *\/`) — the server runs
	 * their content — and unterminated literals or comments.
	 */
	public static function maskLiteralsAndComments(string $sql, bool $isMysql): string
	{
		$masked = '';
		$length = \strlen($sql);
		$position = 0;

		while ($position < $length) {
			$char = $sql[$position];
			$next = $position + 1 < $length ? $sql[$position + 1] : '';

			if ($char === '\'' || $char === '"' || $char === '`') {
				$prefix = $position > 0 ? $sql[$position - 1] : '';
				$backslashEscapes = $char !== '`' && ($isMysql || ($char === '\'' && ($prefix === 'E' || $prefix === 'e')));
				$position = self::findClosingQuote($sql, $position, $char, $backslashEscapes);
				$masked .= ' q ';

				continue;
			}

			if (!$isMysql && $char === '$') {
				$end = self::findDollarQuoteEnd($sql, $position);

				if ($end !== null) {
					$position = $end;
					$masked .= ' q ';

					continue;
				}
			}

			if ($char === '/' && $next === '*') {
				$third = $position + 2 < $length ? $sql[$position + 2] : '';
				$fourth = $position + 3 < $length ? $sql[$position + 3] : '';

				if ($third === '!' || ($third === 'M' && $fourth === '!')) {
					throw new InvalidArgumentException('Executable comments are not allowed.');
				}

				$end = \strpos($sql, '*/', $position + 2);

				if ($end === false) {
					throw new InvalidArgumentException('Unterminated comment.');
				}

				$position = $end + 2;
				$masked .= ' ';

				continue;
			}

			$isLineComment = ($isMysql && $char === '#')
				|| ($char === '-' && $next === '-' && (!$isMysql || $position + 2 >= $length || \ctype_space($sql[$position + 2]) || \ctype_cntrl($sql[$position + 2])));

			if ($isLineComment) {
				$end = \strpos($sql, "\n", $position);
				$position = $end === false ? $length : $end;
				$masked .= ' ';

				continue;
			}

			$masked .= $char;
			$position++;
		}

		return $masked;
	}

	/**
	 * The statement that caps one query's runtime on the given server, or null where none is known.
	 *
	 * MariaDB has no `max_execution_time` (MySQL's, in ms) — it rejects it as an unknown
	 * variable and has its own `max_statement_time` in seconds. Until 3.2.5 the MySQL one was
	 * sent to MariaDB too, the error was swallowed and every query there ran uncapped.
	 * @internal public for tests
	 */
	public static function statementTimeoutSql(string $driver, string $serverVersion, int $seconds): ?string
	{
		$seconds = \max(1, $seconds);

		if ($driver === 'pgsql') {
			return 'SET statement_timeout = ' . ($seconds * 1000);
		}

		if ($driver !== 'mysql') {
			return null;
		}

		return Strings::contains(Strings::lower($serverVersion), 'mariadb')
			? "SET SESSION max_statement_time = {$seconds}"
			: 'SET SESSION max_execution_time = ' . ($seconds * 1000);
	}

	private function executePrepared(PDO $pdo, string $sql): PDOStatement
	{
		$statement = $pdo->prepare($sql);

		if (!$statement instanceof PDOStatement) {
			throw new PDOException('Query could not be prepared.');
		}

		$statement->execute();

		return $statement;
	}

	private function assertReadOnly(string $sql): void
	{
		if ($sql === '') {
			throw new InvalidArgumentException('Empty query.');
		}

		$isMysql = $this->resolveDriver($this->connection['driver']) === 'mysql';
		$trimmed = \ltrim(self::maskLiteralsAndComments($sql, $isMysql), self::TRIM_CHARACTERS);

		if (\preg_match('/^(select|with)\b/i', $trimmed) !== 1) {
			throw new InvalidArgumentException('Only SELECT or WITH queries are allowed.');
		}

		$body = \rtrim(\rtrim($trimmed, self::TRIM_CHARACTERS), ';');

		if (\str_contains($body, ';')) {
			throw new InvalidArgumentException('Multiple statements are not allowed.');
		}

		foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
			if (\preg_match('/\b' . $keyword . '\b/i', $body) === 1) {
				throw new InvalidArgumentException("Disallowed keyword in query: {$keyword}.");
			}
		}
	}

	private function wrapWithLimit(string $sql, int $limit): string
	{
		$body = \rtrim(\rtrim(Strings::trim($sql), self::TRIM_CHARACTERS), ';');

		// The newline ends a trailing `#` / `--` comment of the query, so it cannot swallow the wrapper.
		return "select * from ({$body}\n) as triage_readonly_sub limit {$limit}";
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

		$options = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		];

		if ($driver === 'mysql') {
			// pdo_mysql allows several statements per query() by default. PHP 8.4 added
			// Pdo\Mysql::ATTR_MULTI_STATEMENTS and 8.5 deprecates the PDO::MYSQL_* alias.
			$options[self::mysqlMultiStatementsAttribute()] = false;
		}

		$pdo = new PDO($dsn, $username, $password, $options);

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

	/**
	 * Fail closed: a server that cannot switch the session to read-only does not get the query.
	 */
	private function applyReadOnlySession(PDO $pdo): void
	{
		$pdo->exec($this->resolveDriver($this->connection['driver']) === 'pgsql'
			? 'SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY'
			: 'SET SESSION TRANSACTION READ ONLY');
	}

	private function applyStatementTimeout(PDO $pdo): void
	{
		try {
			$serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
			$sql = self::statementTimeoutSql(
				$this->resolveDriver($this->connection['driver']),
				\is_string($serverVersion) ? $serverVersion : '',
				$this->statementTimeoutSeconds,
			);

			if ($sql !== null) {
				$pdo->exec($sql);
			}
		} catch (Throwable) {
			// Best effort — if the server variant doesn't support the setting, the
			// read still runs (credentials should point at a read-only user regardless).
		}
	}

	/**
	 * @return int position right after the closing quote
	 */
	private static function findClosingQuote(string $sql, int $open, string $quote, bool $backslashEscapes): int
	{
		$length = \strlen($sql);
		$position = $open + 1;

		while ($position < $length) {
			$char = $sql[$position];

			if ($backslashEscapes && $char === '\\') {
				$position += 2;

				continue;
			}

			if ($char === $quote) {
				// A doubled quote is an escaped quote inside the literal in both servers.
				if ($position + 1 < $length && $sql[$position + 1] === $quote) {
					$position += 2;

					continue;
				}

				return $position + 1;
			}

			$position++;
		}

		throw new InvalidArgumentException('Unterminated quoted string.');
	}

	/**
	 * PostgreSQL dollar quote opening at $open (`$$` or `$tag$`, not part of an identifier and not
	 * a `$1` parameter): the position right after its closing tag, or null when none opens there.
	 */
	private static function findDollarQuoteEnd(string $sql, int $open): ?int
	{
		$previous = $open > 0 ? $sql[$open - 1] : '';

		if ($previous !== '' && (\ctype_alnum($previous) || $previous === '_' || $previous === '$')) {
			return null;
		}

		if (\preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $open) !== 1) {
			return null;
		}

		$tag = $match[0];
		$close = \strpos($sql, $tag, $open + \strlen($tag));

		if ($close === false) {
			throw new InvalidArgumentException('Unterminated dollar-quoted string.');
		}

		return $close + \strlen($tag);
	}

	private static function mysqlMultiStatementsAttribute(): int
	{
		$name = \defined('Pdo\\Mysql::ATTR_MULTI_STATEMENTS') ? 'Pdo\\Mysql::ATTR_MULTI_STATEMENTS' : 'PDO::MYSQL_ATTR_MULTI_STATEMENTS';

		if (!\defined($name)) {
			throw new InvalidArgumentException('The pdo_mysql extension is not available.');
		}

		/** @var int $attribute */
		$attribute = \constant($name);

		return $attribute;
	}
}
