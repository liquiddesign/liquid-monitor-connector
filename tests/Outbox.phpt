<?php

declare(strict_types=1);

use LiquidMonitorConnector\Orchestrator\Outbox;
use Symfony\Component\Console\Output\NullOutput;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$outbox = new Outbox();
$root = \sys_get_temp_dir() . '/outbox-test-' . \getmypid();
$output = new NullOutput();

$entry = [
	'task_id' => 7,
	'session_id' => 3,
	'create_turn' => ['idempotency_key' => 'k1', 'turn_number' => 1],
	'post_result' => ['idempotency_key' => 'k2', 'category' => 'answer'],
	'patch_task' => ['status' => 'completed'],
	'create_event' => ['event_type' => 'turn_completed'],
	'patch_session' => ['state' => 'suspended'],
];

// Empty root throws, missing dir reads as empty
Assert::exception(
	fn () => $outbox->enqueue('', 'x', []),
	RuntimeException::class,
);
Assert::same([], $outbox->entries($root));

// Enqueue + read back
$file = $outbox->enqueue($root, 'task7-turn1-abc', $entry);
Assert::true(\is_file($file));
$entries = $outbox->entries($root);
Assert::count(1, $entries);
Assert::same(7, $entries['task7-turn1-abc']['task_id']);
Assert::same([], $entries['task7-turn1-abc']['done']);

// Successful flush executes all steps in order and removes the entry
$calls = [];
$flushed = $outbox->flushWith($root, function (string $step, array $e) use (&$calls): void {
	$calls[] = $step;
}, $output);
Assert::same(1, $flushed);
Assert::same(Outbox::STEPS, $calls);
Assert::same([], $outbox->entries($root));

// Failure mid-flush keeps the entry with completed steps marked done
$outbox->enqueue($root, 'task7-turn2-def', $entry);
$calls = [];
$flushed = $outbox->flushWith($root, function (string $step, array $e) use (&$calls): void {
	if ($step === 'patch_task') {
		throw new RuntimeException('monitor down');
	}

	$calls[] = $step;
}, $output);
Assert::same(0, $flushed);
Assert::same(['create_turn', 'post_result'], $calls);
$entries = $outbox->entries($root);
Assert::count(1, $entries);
Assert::true($entries['task7-turn2-def']['done']['create_turn']);
Assert::true($entries['task7-turn2-def']['done']['post_result']);
Assert::false(isset($entries['task7-turn2-def']['done']['patch_task']));

// Replay run skips the done steps and finishes the rest
$calls = [];
$flushed = $outbox->flushWith($root, function (string $step, array $e) use (&$calls): void {
	$calls[] = $step;
}, $output);
Assert::same(1, $flushed);
Assert::same(['patch_task', 'create_event', 'patch_session'], $calls);
Assert::same([], $outbox->entries($root));

// Entries with missing steps only execute what they carry
$outbox->enqueue($root, 'partial', ['task_id' => 1, 'session_id' => 2, 'patch_task' => ['status' => 'pending']]);
$calls = [];
$outbox->flushWith($root, function (string $step, array $e) use (&$calls): void {
	$calls[] = $step;
}, $output);
Assert::same(['patch_task'], $calls);

// Corrupted entry file is skipped, valid ones still flush
\file_put_contents($root . '/broken.json', '{nope');
$outbox->enqueue($root, 'ok', ['task_id' => 1, 'session_id' => 2, 'patch_task' => ['status' => 'pending']]);
$flushed = $outbox->flushWith($root, function (): void {
}, $output);
Assert::same(1, $flushed);
\unlink($root . '/broken.json');

Tester\Helpers::purge($root);
@\rmdir($root);

echo "\nOK " . __FILE__ . "\n";
