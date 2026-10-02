<?php

declare(strict_types=1);

use LiquidMonitorConnector\Telemetry\Recorder;
use LiquidMonitorConnector\Telemetry\StormBridge;
use LiquidMonitorConnector\Telemetry\TelemetryConfig;
use LiquidMonitorConnector\Telemetry\Transport;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// --- Klíč dotazu: krátký beze změny, dlouhý jen začátek + konec (režie nesmí růst s délkou SQL). ---
$short = "SELECT name FROM t WHERE id = 'x'";
Assert::same($short, StormBridge::key($short));

$columns = 'SELECT ' . \str_repeat('this.col AS col, ', 10000) . 'this.uuid';
$long = $columns . ' FROM eshop_product AS this WHERE (this.uuid = :__var123) GROUP BY this.uuid LIMIT 1';
$key = StormBridge::key($long);
Assert::same(StormBridge::KEY_HEAD_BYTES + \strlen(' … ') + StormBridge::KEY_TAIL_BYTES, \strlen($key));
Assert::true(\str_starts_with($key, 'SELECT this.col AS col, '));
Assert::true(\str_ends_with($key, 'WHERE (this.uuid = :__var123) GROUP BY this.uuid LIMIT 1'));

// --- Normalizace: pojmenované parametry StORM i jejich seznamy splynou. ---
Assert::same(
	'SELECT a FROM t WHERE x IN (?+) AND y = ? AND z = ? AND w = :path LIMIT ?',
	StormBridge::normalizeSql("SELECT a FROM t WHERE x IN (:__var12,:__var13) AND y = :__var14 AND z = 'abc' AND w = :path LIMIT 5"),
);

// --- Přes observer: varianty dlouhého dotazu lišící se číslem parametru (stejné délky) jdou pod jeden klíč. ---
final class StormBridgeMemoryTransport implements Transport
{
	/** @var list<string> */
	public array $sent = [];

	public function send(string $payload): void
	{
		$this->sent[] = $payload;
	}
}

$connection = new class {
	public ?\Closure $observer = null;

	public function setQueryObserver(\Closure $observer): void
	{
		$this->observer = $observer;
	}
};

$config = new TelemetryConfig(enabled: true, cli: true, sampleRate: 0.0, environment: 'test');
StormBridge::attach($connection, $config);
Assert::type(\Closure::class, $connection->observer);

$transport = new StormBridgeMemoryTransport();
Recorder::start($config, $transport);

for ($i = 10; $i < 30; $i++) {
	($connection->observer)($columns . " FROM eshop_product AS this WHERE (this.uuid = :__var{$i}) LIMIT 1", 1_000_000, false);
}

Recorder::flush(200);
Assert::count(1, $transport->sent);
$payload = \json_decode($transport->sent[0], true, 32, \JSON_THROW_ON_ERROR);
Assert::count(1, $payload['keys']['sql']);
Assert::same(20, $payload['keys']['sql'][0][1]);
Assert::true(\str_starts_with($payload['keys']['sql'][0][0], 'h:'));
Recorder::setKeyNormalizer('sql', null);
