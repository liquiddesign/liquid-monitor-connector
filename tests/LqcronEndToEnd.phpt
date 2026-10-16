<?php

declare(strict_types=1);

/**
 * The real lqcron binary against the real PHP side of the connector and a mock
 * LQDeck: sync (export-definitions) → run-job → monitor-worker execute --local →
 * marker → final state → retry → after → outbox → deliver. The Rust test suite
 * uses a fake PHP script; this one checks both halves of docs/v4-contract.md agree.
 */

use LiquidMonitorConnector\Cron;
use Symfony\Component\Process\Process;
use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/fixtures/scheduler.php';

Environment::setup();
\Tracy\Debugger::$logDirectory = \sys_get_temp_dir();

$binary = \getenv('LQCRON_BINARY') ?: __DIR__ . '/../scheduler/target/debug/lqcron';

if (!\is_file($binary)) {
	Environment::skip('lqcron binary not built (cargo build in scheduler/, or set LQCRON_BINARY).');
}

$root = \sys_get_temp_dir() . '/lqcron-e2e-' . \getmypid() . '-' . \bin2hex(\random_bytes(3));
$slot = $root . '/slot';
$stateDir = $root . '/state';
$requests = $root . '/requests.jsonl';
@\mkdir($slot . '/vendor/bin', 0777, true);
@\mkdir($stateDir, 0777, true);
\touch($requests);

$port = 20000 + \getmypid() % 20000;
$fixture = \realpath(__DIR__ . '/fixtures/lqcron-app');
$autoload = \realpath(__DIR__ . '/../vendor/autoload.php');
$connectorBin = \realpath(__DIR__ . '/../bin/monitor-worker');

\file_put_contents($slot . '/local.neon', <<<NEON
liquidMonitorConnector:
	url: 'http://127.0.0.1:{$port}/api/connector'
	scheduler:
		stateDir: {$stateDir}
		phpBinary: '{$_SERVER['_']}'

NEON);
// The binary that runs this test is the PHP lqcron should use for jobs too.
\file_put_contents($slot . '/local.neon', \str_replace("'{$_SERVER['_']}'", "'" . \PHP_BINARY . "'", (string) \file_get_contents($slot . '/local.neon')));

// vendor/bin/monitor-worker of the fixture slot: the real one with the fixture bootstrap.
\file_put_contents($slot . '/vendor/bin/monitor-worker', <<<PHP
<?php
\$hasBootstrap = false;
foreach (\$_SERVER['argv'] as \$argument) {
	\$hasBootstrap = \$hasBootstrap || \\str_starts_with(\$argument, '--bootstrap=');
}
if (!\$hasBootstrap) {
	\$_SERVER['argv'][] = '--bootstrap={$fixture}/bootstrap.php';
}
require '{$connectorBin}';
PHP);

$env = [
	'LQCRON_E2E_SLOT' => $slot,
	'LQCRON_E2E_AUTOLOAD' => $autoload,
	'LQCRON_E2E_REQUESTS' => $requests,
	'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
	// lqcron keeps a per-user pointer to the last stateDir; keep it out of the developer's home.
	'XDG_STATE_HOME' => $root . '/xdg-state',
];

$mock = new Process([\PHP_BINARY, '-S', '127.0.0.1:' . $port, $fixture . '/lqdeck-mock.php'], $slot, $env);
$mock->start();
\usleep(500000);

$lqcron = static function (array $arguments, int $timeout = 60) use ($binary, $slot, $env): Process {
	$process = new Process([$binary, ...$arguments], $slot, $env, null, $timeout);
	$process->run();

	return $process;
};

try {
	// --- sync: PHP export + lqcron creates and migrates the database ---
	$sync = $lqcron(['sync', '--php=' . \PHP_BINARY]);
	Assert::same(0, $sync->getExitCode(), $sync->getErrorOutput() . $sync->getOutput());
	Assert::true(\is_file($slot . '/temp/lqcron/export.json'));

	// The first tick creates the database (it owns the stateDir check, § 4.2) and applies the export.
	$tick = $lqcron(['tick', '--php=' . \PHP_BINARY]);
	Assert::same(0, $tick->getExitCode(), $tick->getErrorOutput() . $tick->getOutput());
	Assert::true(\is_file($stateDir . '/lqcron.sqlite'));

	$crons = \array_column(schedulerRows($stateDir, 'SELECT * FROM crons'), null, 'code');
	Assert::same(['afterImport', 'flaky', 'import'], \array_keys($crons));
	Assert::same('import', $crons['afterImport']['after_code']);

	// --- the application enqueues through the real Cron service ---
	$container = (static function () use ($env, $fixture): object {
		foreach ($env as $name => $value) {
			\putenv($name . '=' . $value);
		}

		return require $fixture . '/bootstrap.php';
	})();
	$container->getByType(Cron::class)->schedulePullJob(\LqcronE2e\ImportHandler::class, ['count' => 3]);
	Assert::same(1, (int) schedulerRows($stateDir, 'SELECT COUNT(*) AS c FROM jobs')[0]['c']);

	// --- run --sync: claim → run-job → execute --local → finished (+ `after` enqueued) ---
	$run = $lqcron(['run', 'import', '--args={"count":5}', '--sync']);
	Assert::same(0, $run->getExitCode(), $run->getErrorOutput() . $run->getOutput());

	$runs = schedulerRows($stateDir, "SELECT * FROM runs WHERE cron_code = 'import' ORDER BY id");
	Assert::same('finished', \end($runs)['state']);
	Assert::same(['imported' => 5], \json_decode(\end($runs)['result'], true));
	Assert::true(\str_contains((string) \file_get_contents($stateDir . '/runs/' . \end($runs)['uuid'] . '.log'), 'importing'));
	Assert::same(1, (int) schedulerRows($stateDir, "SELECT COUNT(*) AS c FROM jobs WHERE cron_code = 'afterImport' AND source = 'after'")[0]['c']);

	// --- a failing cron with repeatCount 1: two attempts, the second final, log tail redacted ---
	$flaky = $lqcron(['run', 'flaky', '--sync']);
	Assert::notSame(0, $flaky->getExitCode());
	Assert::same(1, (int) schedulerRows($stateDir, "SELECT COUNT(*) AS c FROM jobs WHERE cron_code = 'flaky' AND source = 'retry' AND attempt = 2")[0]['c']);

	// The retry is the tick's to claim; run-job is detached, so wait for it.
	$deadline = \microtime(true) + 30;

	do {
		$lqcron(['tick', '--php=' . \PHP_BINARY]);
		\usleep(300000);
		$flakyRuns = schedulerRows($stateDir, "SELECT * FROM runs WHERE cron_code = 'flaky' ORDER BY id");
		$settled = \count($flakyRuns) === 2 && $flakyRuns[1]['state'] === 'failed';
	} while (!$settled && \microtime(true) < $deadline);

	Assert::true($settled, \json_encode($flakyRuns));
	Assert::same('failed', $flakyRuns[0]['state']);
	Assert::same(0, (int) $flakyRuns[0]['final']);
	Assert::same(1, (int) $flakyRuns[1]['final']);
	Assert::same(0, (int) schedulerRows($stateDir, "SELECT COUNT(*) AS c FROM jobs WHERE cron_code = 'flaky'")[0]['c']);

	// --- deliver everything to LQDeck ---
	$flush = $lqcron(['flush', '--timeout=60']);
	Assert::same(0, $flush->getExitCode(), $flush->getErrorOutput() . $flush->getOutput());
	Assert::same(0, (int) schedulerRows($stateDir, 'SELECT COUNT(*) AS c FROM outbox')[0]['c']);

	$sent = \array_map(static fn (string $line): array => \json_decode($line, true), \array_filter(\explode("\n", (string) \file_get_contents($requests))));
	$posts = \array_values(\array_filter($sent, static fn (array $request): bool => $request['method'] === 'POST'));
	Assert::true($posts !== []);
	Assert::same('/api/connector/v4/events', $posts[0]['uri']);
	Assert::same('E2E-KEY', $posts[0]['api_key']);
	Assert::match('4.%a%', (string) $posts[0]['version']);

	$events = \array_merge(...\array_map(static fn (array $post): array => $post['body']['events'], $posts));
	$types = \array_column($events, 'type');
	Assert::contains('definitions.sync', $types);
	Assert::contains('job.queued', $types);
	Assert::contains('run.started', $types);
	Assert::contains('run.progress', $types);
	Assert::contains('run.finished', $types);
	Assert::contains('run.failed', $types);

	$seqs = \array_column($events, 'seq');
	$sorted = $seqs;
	\sort($sorted);
	Assert::same($sorted, $seqs);

	$byType = static fn (string $type): array => \array_values(\array_filter($events, static fn (array $event): bool => $event['type'] === $type));

	$started = $byType('run.started')[0]['payload'];
	foreach (['run_uuid', 'job_uuid', 'origin_uuid', 'cron_code', 'attempt', 'max_attempts', 'source', 'started_at', 'pid'] as $field) {
		Assert::true(\array_key_exists($field, $started), "run.started.$field");
	}

	$finished = $byType('run.finished')[0]['payload'];
	Assert::true($finished['final']);
	Assert::same(['imported' => 5], $finished['result']);
	Assert::true($finished['duration_ms'] >= 0);

	$failed = $byType('run.failed');
	Assert::false($failed[0]['payload']['final']);
	Assert::same('failed', $failed[0]['payload']['outcome']);
	Assert::same('remote API down', $failed[0]['payload']['error']['message']);
	Assert::contains('[redacted]', (string) $failed[0]['payload']['log_tail']);
	Assert::false(\str_contains((string) $failed[0]['payload']['log_tail'], 'hunter2'));
	Assert::true(\end($failed)['payload']['final']);
	Assert::same(2, \end($failed)['payload']['attempt']);
	Assert::same($failed[0]['payload']['origin_uuid'], \end($failed)['payload']['origin_uuid']);

	foreach ($events as $event) {
		Assert::match('%d%-%d%-%d%T%d%:%d%:%d%.%d%+00:00', $event['occurred_at']);
	}

	Assert::true(isset($posts[0]['body']['heartbeat']['instance_id']));
	Assert::true(isset($posts[0]['body']['sent_at']));

	// --- status --json reads the same database ---
	$status = $lqcron(['status', '--json']);
	Assert::same(0, $status->getExitCode(), $status->getErrorOutput());
	Assert::type('array', \json_decode($status->getOutput(), true));
} finally {
	$mock->stop(1);

	// Tick starts `lqcron deliver` (and maybe run-job) detached; without LQDeck a
	// deliver would keep retrying for 10 minutes. Everything started from $root goes.
	(new Process(['pkill', '-f', $root]))->run();
	\usleep(200000);
	(new Process(['rm', '-rf', $root]))->run();
}

echo "\nOK " . __FILE__ . "\n";
