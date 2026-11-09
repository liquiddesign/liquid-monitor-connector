<?php

declare(strict_types=1);

use LiquidMonitorConnector\DbQuery\ReadOnlyQueryRunner;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$connection = [
	'driver' => 'mariadb',
	'host' => '127.0.0.1',
	'port' => 3306,
	'database' => 'test',
	'username' => 'user',
	'password' => 'pass',
];

$assertRejects = static function (string $sql) use ($connection): void {
	$runner = new ReadOnlyQueryRunner($connection);
	Assert::exception(static fn () => $runner->run($sql), InvalidArgumentException::class);
};

// --- Read-only guards ---
$assertRejects('');
$assertRejects('DELETE FROM users');
$assertRejects('SELECT 1; SELECT 2');
$assertRejects('SELECT 1; DROP TABLE users');
$assertRejects('WITH cte AS (INSERT INTO t VALUES (1)) SELECT * FROM cte');
$assertRejects('SELECT 1 /* comment */ ; SELECT 2');

foreach (['insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate'] as $keyword) {
	$assertRejects("SELECT 1 FROM t WHERE {$keyword} = 1");
}

// A comment marker inside a string literal must not hide the rest of the query from the check
// (before 4.2.0 `#…` / `--…` was stripped even inside literals while the server ran the original).
$assertRejects("select '#' ) as t; drop table x; select * from (select 1");
$assertRejects("select '-- ' ) as t; delete from x; select * from (select 1");
$assertRejects('select "#"; drop table x');
// MySQL / MariaDB run the content of executable comments.
$assertRejects('select 1 /*! ; drop table x */');
$assertRejects('select 1 /*!50000 , (delete from x) */');
$assertRejects('select 1 /*M! ; drop table x */');
// Unterminated literal / comment.
$assertRejects("select 'abc");
$assertRejects('select 1 /* never closed');
// `--x` is not a comment in MySQL (it is minus minus x), so what follows stays checked.
$assertRejects('select 1 --x ; drop table t');

$mask = static fn (string $sql, bool $isMysql = true): string => ReadOnlyQueryRunner::maskLiteralsAndComments($sql, $isMysql);

// Literals and quoted identifiers are masked, so their content neither fails nor fakes the check.
Assert::same("select  q  from t where status =  q ", $mask("select `delete` from t where status = 'update;'"));
Assert::same('select  q ,  q ', $mask("select 'it''s', 'a\\'b'"));
Assert::same('select 1  ', $mask('select 1 # trailing'));
Assert::same("select 1  \n", $mask("select 1 -- trailing\n"));
Assert::same('select 1  , 2', $mask('select 1 /* c */, 2'));
// PostgreSQL: `#` is an operator, backslash escapes only in E'…' strings.
Assert::same('select 1 # 2', $mask('select 1 # 2', false));
Assert::same('select  q   q ', $mask("select 'a\\' 'b'", false));
Assert::same("select E q ", $mask("select E'a\\'b'", false));

// Allowed starters.
$wrapLimit = static function (string $sql, int $limit) use ($connection): string {
	$ref = new ReflectionClass(ReadOnlyQueryRunner::class);
	$method = $ref->getMethod('wrapWithLimit');
	// PHP 8.0 refuses to invoke a private method without this; it is a no-op since 8.1
	// and deprecated since 8.5, so only call it where it matters.
	if (\PHP_VERSION_ID < 80100) {
		$method->setAccessible(true);
	}

	/** @var string $wrapped */
	$wrapped = $method->invoke(new ReadOnlyQueryRunner($connection), $sql, $limit);

	return $wrapped;
};

Assert::same("select * from (SELECT 1\n) as triage_readonly_sub limit 50", $wrapLimit('SELECT 1', 50));
Assert::same("select * from (WITH cte AS (SELECT 1 AS n) SELECT n FROM cte\n) as triage_readonly_sub limit 10", $wrapLimit('WITH cte AS (SELECT 1 AS n) SELECT n FROM cte', 10));
Assert::same("select * from (SELECT 1\n) as triage_readonly_sub limit 100", $wrapLimit('SELECT 1;', 100));
// A trailing line comment of the query ends at the newline and cannot swallow the wrapper.
Assert::same("select * from (SELECT 1 -- note\n) as triage_readonly_sub limit 5", $wrapLimit('SELECT 1 -- note', 5));

// --- Bindings ---
Assert::same([], ReadOnlyQueryRunner::normalizeBindings([]));
Assert::same([1, 'a', 1.5, true, null], ReadOnlyQueryRunner::normalizeBindings([1, 'a', 1.5, true, null]));

// A JSON object (named keys) or a sparse list is not a positional list.
Assert::exception(
	static fn () => ReadOnlyQueryRunner::normalizeBindings(['id' => 1]),
	InvalidArgumentException::class,
	'"bindings" must be a list of positional values.',
);
Assert::exception(
	static fn () => ReadOnlyQueryRunner::normalizeBindings([1 => 'a']),
	InvalidArgumentException::class,
	'"bindings" must be a list of positional values.',
);

// Nested arrays / objects are rejected — and before any connection is opened
// (127.0.0.1:3306 with these credentials would otherwise fail with PDOException).
Assert::exception(
	static fn () => (new ReadOnlyQueryRunner($connection))->run('SELECT ?', [[1, 2]]),
	InvalidArgumentException::class,
	'Binding #0 must be a scalar or null.',
);

// Unsupported driver is rejected before connecting.
Assert::exception(
	static fn () => (new ReadOnlyQueryRunner([
		'driver' => 'sqlite',
		'host' => '127.0.0.1',
		'database' => ':memory:',
		'username' => 'u',
	]))->run('SELECT 1'),
	InvalidArgumentException::class,
	'Unsupported database driver.',
);

echo "\nOK " . __FILE__ . "\n";
