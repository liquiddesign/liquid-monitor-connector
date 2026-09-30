<?php

declare(strict_types=1);

use LiquidMonitorConnector\Telemetry\Agent\AgentLauncher;
use LiquidMonitorConnector\Telemetry\Recorder;
use LiquidMonitorConnector\Telemetry\TelemetryConfig;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// Skutečný proces agenta: recorder → UDP → agent → JSONL.
$outDir = __DIR__ . '/temp/telemetry-agent-' . \getmypid();
$port = 40000 + \getmypid() % 20000;
$config = new TelemetryConfig(enabled: true, cli: true, port: $port, environment: 'e2e', sampleRate: 1.0, agentMaxRuntime: 2);

$command = AgentLauncher::command($config, __DIR__ . '/../bin/monitor-telemetry-agent', $outDir, \PHP_BINARY);
$process = \proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
Assert::type('resource', $process);
\usleep(400_000);

for ($i = 0; $i < 3; $i++) {
	Recorder::start($config);
	Recorder::setRoute('E2E:run');
	Recorder::record('sql', 'SELECT 1', 1_000_000);
	Recorder::flush(200);
}

$stderr = (string) \stream_get_contents($pipes[2]);
$exitCode = \proc_close($process);
Assert::same(0, $exitCode, $stderr);

$files = \glob($outDir . '/telemetry-*.jsonl') ?: [];
Assert::count(1, $files);

$received = 0;
$sql = 0;

foreach (\file($files[0], \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
	$minute = \json_decode($line, true, 64, \JSON_THROW_ON_ERROR);
	$received += $minute['received'];

	foreach ($minute['requests'] as $request) {
		Assert::same('e2e', $request['env']);
		Assert::same('E2E:run', $request['route']);
	}

	foreach ($minute['ops'] as $operation) {
		$sql += $operation['count'];
	}

	Assert::count($minute['received'], $minute['traces']);
}

Assert::same(3, $received);
Assert::same(3, $sql);

Nette\Utils\FileSystem::delete($outDir);
