<?php

declare(strict_types=1);

use LiquidMonitorConnector\Orchestrator\TurnStateStore;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$store = new TurnStateStore();
$worktree = \sys_get_temp_dir() . '/turn-state-test-' . \getmypid();
@\mkdir($worktree, 0o777, true);

// No state yet
Assert::null($store->read($worktree));

// Write + read roundtrip (creates .orchestrator/ dir)
$state = [
	'task_id' => 1,
	'session_id' => 5,
	'turn_number' => 2,
	'phase' => 'work',
	'submitted_at' => '2026-06-03T16:00:00+02:00',
	'pane_sha1' => 'abc',
	'nudges' => 0,
];
$store->write($worktree, $state);
Assert::equal($state, $store->read($worktree));
Assert::true(\is_file($worktree . '/' . TurnStateStore::TURN_STATE_RELATIVE_PATH));

// Update
$state['nudges'] = 1;
$store->write($worktree, $state);
$read = $store->read($worktree);
Assert::notNull($read);
Assert::same(1, $read['nudges']);

// Clear (idempotent)
$store->clear($worktree);
Assert::null($store->read($worktree));
$store->clear($worktree);

// Corrupted file is treated as no open turn
\file_put_contents($worktree . '/' . TurnStateStore::TURN_STATE_RELATIVE_PATH, '{broken');
Assert::null($store->read($worktree));

// Empty worktree path
Assert::null($store->read(''));
Assert::exception(
	fn () => $store->write('', ['x' => 1]),
	RuntimeException::class,
);

Tester\Helpers::purge($worktree);
@\rmdir($worktree);

echo "\nOK " . __FILE__ . "\n";
