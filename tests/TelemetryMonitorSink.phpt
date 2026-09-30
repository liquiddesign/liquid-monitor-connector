<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LiquidMonitorConnector\Telemetry\Agent\AgentLauncher;
use LiquidMonitorConnector\Telemetry\Agent\MonitorSink;
use LiquidMonitorConnector\Telemetry\TelemetryConfig;
use LiquidMonitorConnector\Version;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$spoolDir = __DIR__ . '/temp/telemetry-sink-' . \getmypid();
$minute = static fn (int $minute): array => ['v' => 1, 'minute' => $minute, 'requests' => [], 'ops' => [], 'traces' => [], 'texts' => []];

/** @var list<array{request: Psr\Http\Message\RequestInterface}> $history */
$history = [];
$mock = new MockHandler([
	new ConnectException('down', new Request('POST', '/')),   // 1. flush: monitor nedostupný → fronta
	new Response(503),                                         // 2. flush: 5xx → fronta
	new Response(202),                                         // 3. flush: OK
	new Response(202),                                         //    + dohnání fronty (1. záznam)
	new Response(202),                                         //    + dohnání fronty (2. záznam)
	new Response(401),                                         // 4. flush: špatný klíč — neopakovat
]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$sink = new MonitorSink('https://monitor.example/api/connector/', 'secret', $spoolDir, client: new Client(['handler' => $stack]));

$sink->write([$minute(60)]);
Assert::count(1, \file($spoolDir . '/spool.jsonl') ?: []);

$sink->write([$minute(120)]);
Assert::count(2, \file($spoolDir . '/spool.jsonl') ?: []);

$sink->write([$minute(180)]);
Assert::false(\is_file($spoolDir . '/spool.jsonl'));
Assert::count(5, $history);

$request = $history[2]['request'];
Assert::same('https://monitor.example/api/connector/perf', (string) $request->getUri());
Assert::same(Version::CURRENT, $request->getHeaderLine(Version::HEADER_NAME));
$body = \json_decode((string) $request->getBody(), true);
Assert::same('secret', $body['apiKey']);
Assert::same(180, $body['minutes'][0]['minute']);

$resent = \array_map(static fn (array $entry): int => \json_decode((string) $entry['request']->getBody(), true)['minutes'][0]['minute'], \array_slice($history, 3, 2));
Assert::same([60, 120], $resent);

$sink->write([$minute(240)]);
Assert::false(\is_file($spoolDir . '/spool.jsonl'));

// --- Klíč jde přes prostředí, ne v argumentech. ---
$command = AgentLauncher::command(new TelemetryConfig(enabled: true), '/app/vendor/bin/monitor-telemetry-agent', '/tmp/t', 'php', 'https://m/api/connector', 'k3y');
Assert::match("LQDECK_API_KEY='k3y' 'php' '/app/vendor/bin/monitor-telemetry-agent' %A% --monitor-url='https://m/api/connector'", $command);
Assert::notContains('--api-key', $command);
Assert::contains('--max-runtime=360', AgentLauncher::command(new TelemetryConfig(enabled: true), 'agent', '/tmp/t', 'php', maxRuntime: 360));
Assert::notContains('LQDECK_API_KEY', AgentLauncher::command(new TelemetryConfig(enabled: true), 'agent', '/tmp/t', 'php'));

// --- Na pozadí s klíčem: proměnná musí stát před `nohup` (3.2.0 agenta s klíčem nespustil). ---
$detached = AgentLauncher::detachedCommand(new TelemetryConfig(enabled: true), 'agent', '/tmp/t', 'php', 'https://m/api/connector', 'k3y');
Assert::match("LQDECK_API_KEY='k3y' nohup 'php' 'agent' %A% > /dev/null 2>&1 &", $detached);

// Skutečně spuštěný agent s klíčem a nedosažitelným monitorem musí přijmout datagram a odložit ho do fronty.
$port = 41000 + \getmypid() % 20000;
$agentDir = $spoolDir . '-agent';
$config = new TelemetryConfig(enabled: true, cli: true, port: $port, agentMaxRuntime: 2);
AgentLauncher::spawnDetached($config, __DIR__ . '/../bin/monitor-telemetry-agent', $agentDir, \PHP_BINARY, 'http://127.0.0.1:9/api/connector', 'k3y');
\usleep(600_000);
LiquidMonitorConnector\Telemetry\Recorder::start($config);
LiquidMonitorConnector\Telemetry\Recorder::setRoute('Spawn:test');
LiquidMonitorConnector\Telemetry\Recorder::flush(200);

$deadline = \microtime(true) + 8;

while (!\is_file($agentDir . '/spool.jsonl') && \microtime(true) < $deadline) {
	\usleep(200_000);
}

Assert::true(\is_file($agentDir . '/spool.jsonl'), 'agent spawned with an API key did not run');
Assert::contains('Spawn:test', (string) \file_get_contents($agentDir . '/spool.jsonl'));
Nette\Utils\FileSystem::delete($agentDir);

Nette\Utils\FileSystem::delete($spoolDir);
