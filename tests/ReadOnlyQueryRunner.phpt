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

Assert::same('select * from (SELECT 1) as triage_readonly_sub limit 50', $wrapLimit('SELECT 1', 50));
Assert::same('select * from (WITH cte AS (SELECT 1 AS n) SELECT n FROM cte) as triage_readonly_sub limit 10', $wrapLimit('WITH cte AS (SELECT 1 AS n) SELECT n FROM cte', 10));
Assert::same('select * from (SELECT 1) as triage_readonly_sub limit 100', $wrapLimit('SELECT 1;', 100));

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
