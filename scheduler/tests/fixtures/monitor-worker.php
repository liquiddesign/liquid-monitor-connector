<?php

// Fake `vendor/bin/monitor-worker` pro integrační test lqcron. Simuluje PHP stranu
// kontraktu (docs/v4-contract.md § 2.2, § 3, § 4, § 7): execute --local, export-definitions,
// redact-log. Používá rozšíření sqlite3 (pdo_sqlite na stroji být nemusí).

declare(strict_types=1);

$opts = [];
$positional = [];
foreach (array_slice($argv, 1) as $a) {
	if (preg_match('/^--([^=]+)=(.*)$/s', $a, $m)) {
		$opts[$m[1]] = $m[2];
	} elseif (str_starts_with($a, '--')) {
		$opts[substr($a, 2)] = true;
	} else {
		$positional[] = $a;
	}
}

$command = $positional[0] ?? '';

if ($command === 'export-definitions') {
	$source = __DIR__ . '/../../export.source.json';
	$out = $opts['output'];
	copy($source, $out . '.tmp');
	rename($out . '.tmp', $out);
	exit(0);
}

if ($command === 'redact-log') {
	echo str_replace('SECRET', '***', (string) stream_get_contents(STDIN));
	exit(0);
}

if ($command !== 'execute') {
	fwrite(STDERR, "unknown command $command\n");
	exit(64);
}

function uuid(): string
{
	$b = random_bytes(16);
	$b[6] = chr((ord($b[6]) & 0x0f) | 0x70);
	$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
	$h = bin2hex($b);

	return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

function iso(int $ms): string
{
	return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03d+00:00', $ms % 1000);
}

$stateDir = (string) getenv('LQCRON_STATE_DIR');
$runId = (string) $opts['run-id'];
$code = (string) $opts['cron-code'];

$db = new SQLite3($stateDir . '/lqcron.sqlite');
$db->busyTimeout(5000);
$minReader = (int) $db->querySingle("SELECT value FROM meta WHERE key = 'min_reader_version'");
if ($minReader > 1) {
	exit(70);
}

$stat = (string) file_get_contents('/proc/self/stat');
$fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
$pidStart = (int) $fields[19];
$now = (int) floor(microtime(true) * 1000);

$db->exec('BEGIN IMMEDIATE');
$st = $db->prepare("UPDATE runs SET state = 'running', pid = :pid, pid_start = :ps, started_at = :now, app_path = :app WHERE uuid = :u AND state = 'starting'");
$st->bindValue(':pid', getmypid());
$st->bindValue(':ps', $pidStart);
$st->bindValue(':now', $now);
$st->bindValue(':app', (string) realpath(getcwd()));
$st->bindValue(':u', $runId);
$st->execute();
if ($db->changes() !== 1) {
	$db->exec('ROLLBACK');
	exit(0);
}
$ev = $db->prepare("INSERT INTO outbox (event_id, type, run_uuid, payload, encoding, occurred_at, created_at) VALUES (:e, 'run.started', :u, :p, 'json', :now, :now)");
$ev->bindValue(':e', uuid());
$ev->bindValue(':u', $runId);
$ev->bindValue(':p', json_encode(['run_uuid' => $runId, 'cron_code' => $code, 'started_at' => iso($now), 'pid' => getmypid(), 'hostname' => gethostname()]));
$ev->bindValue(':now', $now);
$ev->execute();
$db->exec('COMMIT');
$db->close();

$arguments = file_get_contents((string) $opts['arguments-file']);
echo "hello from $code, arguments $arguments, timeout " . getenv('LQCRON_TIMEOUT') . "\n";

function marker(string $stateDir, string $runId, string $status, mixed $result, ?array $error): void
{
	$m = [
		'run_uuid' => $runId,
		'status' => $status,
		'explicit_fail' => false,
		'result' => $result,
		'result_truncated' => false,
		'error' => $error,
		'peak_memory_mb' => 2,
		'finished_at' => iso((int) floor(microtime(true) * 1000)),
	];
	$path = "$stateDir/runs/$runId.exit.json";
	file_put_contents($path . '.tmp', json_encode($m));
	rename($path . '.tmp', $path);
}

switch ($code) {
	case 'ok':
	case 'after_ok':
		marker($stateDir, $runId, 'finished', ['imported' => 120], null);
		exit(0);
	case 'fail':
		echo "SECRET token in the log\n";
		marker($stateDir, $runId, 'failed', null, ['class' => 'RuntimeException', 'message' => 'boom', 'trace' => '#0 {main}']);
		exit(1);
	case 'slow':
		// vnouče ve stejné skupině procesů: musí skončit spolu s během
		exec('sleep 60 > /dev/null 2>&1 &');
		sleep(60);
		marker($stateDir, $runId, 'finished', null, null);
		exit(0);
	case 'crash':
		exit(3);
}

exit(0);
