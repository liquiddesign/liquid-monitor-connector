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

	/** @var string $wrapped */
	$wrapped = $method->invoke(new ReadOnlyQueryRunner($connection), $sql, $limit);

	return $wrapped;
};

Assert::same('select * from (SELECT 1) as triage_readonly_sub limit 50', $wrapLimit('SELECT 1', 50));
Assert::same('select * from (WITH cte AS (SELECT 1 AS n) SELECT n FROM cte) as triage_readonly_sub limit 10', $wrapLimit('WITH cte AS (SELECT 1 AS n) SELECT n FROM cte', 10));
Assert::same('select * from (SELECT 1) as triage_readonly_sub limit 100', $wrapLimit('SELECT 1;', 100));

// --- Statement timeout ---
// MariaDB rejects MySQL's max_execution_time (unknown variable); it has max_statement_time in seconds.
Assert::same('SET SESSION max_statement_time = 7', ReadOnlyQueryRunner::statementTimeoutSql('mysql', '11.4.13-MariaDB-deb12', 7));
Assert::same('SET SESSION max_statement_time = 5', ReadOnlyQueryRunner::statementTimeoutSql('mysql', '5.5.5-10.11.6-MariaDB-0+deb12u1', 5));
Assert::same('SET SESSION max_execution_time = 7000', ReadOnlyQueryRunner::statementTimeoutSql('mysql', '8.0.36', 7));
Assert::same('SET statement_timeout = 7000', ReadOnlyQueryRunner::statementTimeoutSql('pgsql', '16.2', 7));
Assert::same('SET SESSION max_statement_time = 1', ReadOnlyQueryRunner::statementTimeoutSql('mysql', '10.6.0-MariaDB', 0));
Assert::null(ReadOnlyQueryRunner::statementTimeoutSql('sqlite', '3.45', 5));

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
