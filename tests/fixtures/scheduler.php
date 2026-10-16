<?php

declare(strict_types=1);

/**
 * Helpers for scheduler tests: a state directory with a database created from
 * the very schema lqcron uses (scheduler/src/schema_v1.sql) — PHP itself never
 * creates the database.
 */

function schedulerStateDir(string $name): string
{
	$dir = \sys_get_temp_dir() . '/lqcron-test-' . $name . '-' . \getmypid() . '-' . \bin2hex(\random_bytes(3));
	@\mkdir($dir . '/runs', 0777, true);

	$sqlite = new \SQLite3($dir . '/lqcron.sqlite');
	$sqlite->exec('PRAGMA journal_mode=WAL');
	$sqlite->exec((string) \file_get_contents(__DIR__ . '/../../scheduler/src/schema_v1.sql'));
	$sqlite->exec("INSERT INTO meta (key, value) VALUES ('schema_version', '1'), ('min_reader_version', '1')");
	$sqlite->close();

	return $dir;
}

/**
 * @return list<array<string, mixed>>
 */
function schedulerRows(string $stateDir, string $sql): array
{
	$sqlite = new \SQLite3($stateDir . '/lqcron.sqlite');
	// lqcron's detached processes (deliver, sync, run-job) write concurrently.
	$sqlite->busyTimeout(10000);
	$result = $sqlite->query($sql);
	$rows = [];

	while ($result !== false && ($row = $result->fetchArray(\SQLITE3_ASSOC)) !== false) {
		$rows[] = $row;
	}

	$sqlite->close();

	return $rows;
}

function schedulerExec(string $stateDir, string $sql): void
{
	$sqlite = new \SQLite3($stateDir . '/lqcron.sqlite');
	$sqlite->busyTimeout(10000);
	$sqlite->exec($sql);
	$sqlite->close();
}
