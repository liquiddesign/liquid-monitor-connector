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
Assert::notContains('LQDECK_API_KEY', AgentLauncher::command(new TelemetryConfig(enabled: true), 'agent', '/tmp/t', 'php'));

Nette\Utils\FileSystem::delete($spoolDir);
