<?php

declare(strict_types=1);

use LiquidMonitorConnector\Telemetry\Agent\Aggregator;
use LiquidMonitorConnector\Telemetry\Agent\Histogram;
use LiquidMonitorConnector\Telemetry\Recorder;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// --- Histogram: logaritmické buckety, sčitatelné, percentil s chybou < 10 %. ---
Assert::same(0, Histogram::index(0.05));
$histogram = [];

for ($ms = 1; $ms <= 1000; $ms++) {
	Histogram::add($histogram, (float) $ms);
}

$p50 = Histogram::percentile($histogram, 0.5);
$p99 = Histogram::percentile($histogram, 0.99);
Assert::true($p50 >= 500 && $p50 <= 550, "p50 = {$p50}");
Assert::true($p99 >= 990 && $p99 <= 1090, "p99 = {$p99}");
Assert::null(Histogram::percentile([], 0.5));
Assert::same(2000, \array_sum(Histogram::merge($histogram, $histogram)));

// --- Aggregator ---
$datagram = static fn (array $overrides = []): string => (string) \json_encode(\array_merge([
	'v' => Recorder::PROTOCOL_VERSION,
	'k' => 'req',
	'env' => 'prod',
	'ts' => 1_790_000_000.5,
	'route' => 'Front:Product:detail',
	'st' => 200,
	'ms' => 120.0,
	'mem' => 40.0,
	'ph' => ['boot' => 20.0, 'presenter' => 80.0],
	'proc' => ['pid' => 1, 'age' => 10.0, 'comp' => 0, 'cold' => false],
	'tags' => ['shop' => 'abel'],
	'err' => null,
	'keys' => ['sql' => [['SELECT 1', 12, 30.0, 5.0, 0]]],
	'tx' => null,
	'spans' => null,
	'ovh' => 50.0,
], $overrides));

$aggregator = new Aggregator(maxTracesPerMinute: 1, maxKeysPerMinute: 2);
Assert::false($aggregator->ingest('not json'));
Assert::false($aggregator->ingest((string) \json_encode(['v' => 999])));
Assert::true($aggregator->ingest($datagram()));
Assert::true($aggregator->ingest($datagram(['ms' => 300.0, 'proc' => ['cold' => true, 'comp' => 800], 'keys' => ['sql' => [['SELECT 1', 3, 6.0, 2.0, 1]]], 'spans' => [['sql', 0, 1000, 2000]]])));
Assert::true($aggregator->ingest($datagram(['st' => 500, 'spans' => [], 'tx' => ['h:abc' => 'SELECT long']])));
// další minuta
Assert::true($aggregator->ingest($datagram(['ts' => 1_790_000_075.0])));

$minutes = $aggregator->drain(1_790_000_040);
Assert::count(1, $minutes);
[$minute] = $minutes;
Assert::same(1_789_999_980, $minute['minute']);
Assert::same(3, $minute['received']);
Assert::same(2, $minute['invalid']);
Assert::count(1, $minute['requests']);

$request = $minute['requests'][0];
Assert::same('Front:Product:detail', $request['route']);
Assert::same(['shop' => 'abel'], $request['tags']);
Assert::same(3, $request['count']);
Assert::same(540.0, $request['sum_ms']);
Assert::same(300.0, $request['max_ms']);
Assert::same(1, $request['errors']);
Assert::same(1, $request['cold']);
Assert::same(800, $request['compiled']);
Assert::same(60.0, $request['phases']['boot']);
Assert::same(3, \array_sum($request['hist']));

$operation = $minute['ops'][0];
Assert::same('sql', $operation['type']);
Assert::same(27, $operation['count']);
Assert::same(3, $operation['requests']);
Assert::same(12, $operation['max_per_request']);
Assert::same(1, $operation['errors']);

// strop tras na minutu
Assert::count(1, $minute['traces']);
Assert::same(['h:abc' => 'SELECT long'], $minute['texts']);

// zbytek (další minuta) až při drain() bez limitu
$rest = $aggregator->drain();
Assert::count(1, $rest);
Assert::same(1_790_000_040, $rest[0]['minute']);
Assert::same([], $aggregator->drain());

// strop kardinality: třetí route jde do _other
$aggregator = new Aggregator(maxKeysPerMinute: 2);
$aggregator->ingest($datagram(['route' => 'A:a']));
$aggregator->ingest($datagram(['route' => 'B:b']));
$aggregator->ingest($datagram(['route' => 'C:c']));
$routes = \array_column($aggregator->drain()[0]['requests'], 'route');
Assert::same(['A:a', 'B:b', Recorder::KEY_OVERFLOW], $routes);
