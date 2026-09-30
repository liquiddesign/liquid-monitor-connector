<?php

declare(strict_types=1);

use LiquidMonitorConnector\Telemetry\Recorder;
use LiquidMonitorConnector\Telemetry\TelemetryConfig;
use LiquidMonitorConnector\Telemetry\Transport;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

final class MemoryTransport implements Transport
{
	/** @var list<string> */
	public array $sent = [];

	public function send(string $payload): void
	{
		$this->sent[] = $payload;
	}
}

$config = static fn (array $overrides = []): TelemetryConfig => new TelemetryConfig(...\array_merge(['enabled' => true, 'cli' => true, 'sampleRate' => 0.0, 'environment' => 'test'], $overrides));

/**
 * @return array<string, mixed>
 */
$decode = static function (MemoryTransport $transport): array {
	Assert::count(1, $transport->sent);
	$payload = \json_decode($transport->sent[0], true, 32, \JSON_THROW_ON_ERROR);
	\assert(\is_array($payload));

	return $payload;
};

/**
 * @param array<string, mixed> $payload
 * @return array{int, list<mixed>} [index, záznam klíče]
 */
$key = static function (array $payload, string $type, string $ref): array {
	foreach ($payload['keys'][$type] as $index => $entry) {
		if ($entry[0] === $ref) {
			return [$index, $entry];
		}
	}

	Assert::fail("key {$type}/{$ref} not found");
};

// --- Vypnutá telemetrie: nic se neaktivuje, API je no-op. ---
Recorder::reset();
$transport = new MemoryTransport();
Recorder::start(new TelemetryConfig(enabled: false), $transport);
Assert::false(Recorder::isActive());
Assert::same(0, Recorder::begin());
Recorder::record('sql', 'SELECT 1', 1000);
Assert::same(42, Recorder::measure('x', 'y', static fn (): int => 42));
Recorder::flush();
Assert::same([], $transport->sent);

// --- CLI se bez `cli: true` neměří. ---
Recorder::start(new TelemetryConfig(enabled: true), $transport);
Assert::false(Recorder::isActive());

// --- Agregace po typu a klíči, fáze, route, tagy, chyby. ---
$transport = new MemoryTransport();
Recorder::start($config(), $transport, \microtime(true) - 0.05);
Assert::true(Recorder::isActive());
Recorder::mark('startup');
Recorder::setRoute('Front:Product:detail');
Recorder::setRoute('Error:default');
Recorder::tag('shop', 'abel');
Recorder::record('sql', 'SELECT * FROM a WHERE id = ?', 2_000_000);
Recorder::record('sql', 'SELECT * FROM a WHERE id = ?', 4_000_000);
Recorder::record('sql', 'UPDATE a SET x = 1', 1_000_000, true);
$start = Recorder::begin();
Recorder::end('qi', 'UCLGetAllGoods_ex', $start);
Assert::exception(static fn () => Recorder::measure('http', 'api.example', static fn () => throw new RuntimeException('down')), RuntimeException::class);
Recorder::mark('send');
Recorder::flush(200);
Assert::false(Recorder::isActive());

$payload = $decode($transport);
Assert::same(1, $payload['v']);
Assert::same('test', $payload['env']);
Assert::same('Front:Product:detail', $payload['route']);
Assert::same(200, $payload['st']);
Assert::same(['shop' => 'abel'], $payload['tags']);
Assert::same([3, 7, 4, 1], $payload['agg']['sql']);
Assert::same(['SELECT * FROM a WHERE id = ?', 2, 6, 4, 0], $key($payload, 'sql', 'SELECT * FROM a WHERE id = ?')[1]);
Assert::same(1, $payload['agg']['qi'][0]);
Assert::same(1, $key($payload, 'http', 'api.example')[1][4]);
Assert::true($payload['ph']['boot'] >= 50.0);
Assert::same(['boot', 'startup', 'send'], \array_keys($payload['ph']));
Assert::true($payload['ms'] >= 50.0);
Assert::null($payload['spans']);
Assert::false($payload['smp']);
Assert::type('float', $payload['ovh']);

// --- Chybový request jde vždy s časovou osou; dlouhý klíč jako hash + text. ---
$transport = new MemoryTransport();
Recorder::start($config(), $transport);
$longSql = 'SELECT ' . \str_repeat('col, ', 60) . 'x FROM t';
Recorder::record('sql', $longSql, 3_000_000);
Recorder::markError(RuntimeException::class);
Recorder::flush(500);

$payload = $decode($transport);
$hash = 'h:' . \hash('crc32b', $longSql);
Assert::true($payload['smp']);
Assert::same(RuntimeException::class, $payload['err']);
Assert::same([$hash, 1, 3, 3, 0], $key($payload, 'sql', $hash)[1]);
Assert::same($longSql, $payload['tx'][$hash]);
Assert::same('sql', $payload['spans'][0][0]);
Assert::same($key($payload, 'sql', $hash)[0], $payload['spans'][0][1]);
Assert::same(3000, $payload['spans'][0][3]);

// --- Stropy: klíče nad limit do `_other`, spany nad limit se jen počítají. ---
$transport = new MemoryTransport();
Recorder::start($config(['maxKeysPerType' => 3, 'maxSpans' => 5, 'sampleRate' => 1.0]), $transport);

for ($i = 0; $i < 10; $i++) {
	Recorder::record('sql', "SELECT {$i}", 1000);
}

Recorder::flush(200);
$payload = $decode($transport);
Assert::count(4, $payload['keys']['sql']);
Assert::same(7, $key($payload, 'sql', Recorder::KEY_OVERFLOW)[1][1]);
Assert::same(10, $payload['agg']['sql'][0]);
Assert::count(5, $payload['spans']);
Assert::same(5, $payload['ds']);

// --- Přetečení s normalizací: varianty téhož dotazu se slijí, `_other` až nad dvojnásobkem stropu. ---
Recorder::setKeyNormalizer('sql', LiquidMonitorConnector\Telemetry\StormBridge::normalizeSql(...));
$transport = new MemoryTransport();
Recorder::start($config(['maxKeysPerType' => 3]), $transport);

for ($i = 0; $i < 50; $i++) {
	Recorder::record('sql', "SELECT * FROM t WHERE id IN ('a{$i}', 'b{$i}')", 1000);
}

Recorder::flush(200);
$payload = $decode($transport);
Assert::count(4, $payload['keys']['sql']);
Assert::same(47, $key($payload, 'sql', 'SELECT * FROM t WHERE id IN (?+)')[1][1]);
Recorder::setKeyNormalizer('sql', null);

// --- Pomalá operace: slow záznam s backtrace, nejvýš maxBacktraces. ---
$transport = new MemoryTransport();
Recorder::start($config(['slowSpanMs' => 1.0, 'maxBacktraces' => 1, 'maxSlowSpans' => 2]), $transport);
Recorder::record('sql', 'SELECT slow', 5_000_000);
Recorder::record('sql', 'SELECT slow', 6_000_000);
Recorder::record('sql', 'SELECT slow', 7_000_000);
Recorder::flush(200);
$payload = $decode($transport);
Assert::count(2, $payload['slow']);
Assert::same($key($payload, 'sql', 'SELECT slow')[0], $payload['slow'][0]['i']);
Assert::true(isset($payload['slow'][0]['bt']));
Assert::false(isset($payload['slow'][1]['bt']));
Assert::contains('TelemetryRecorder.phpt', $payload['slow'][0]['bt'][0]);

// --- Strop datagramu: nejdřív padají spany, pak texty. ---
$transport = new MemoryTransport();
Recorder::start($config(['maxPayloadBytes' => 1500, 'sampleRate' => 1.0, 'maxSpans' => 300]), $transport);

for ($i = 0; $i < 100; $i++) {
	Recorder::record('sql', 'SELECT 1', 1000);
}

Recorder::flush(200);
$payload = $decode($transport);
Assert::null($payload['spans']);
Assert::true($payload['trunc']);
Assert::true(\strlen($transport->sent[0]) <= 1500);

// --- Transport, který vyhodí výjimku, request nerozbije. ---
Recorder::start($config(), new class implements Transport {
	public function send(string $payload): void
	{
		throw new RuntimeException('network');
	}
});
Recorder::record('sql', 'SELECT 1', 1000);
Recorder::flush(200);
Assert::false(Recorder::isActive());
