<?php

declare(strict_types=1);

/**
 * Režie recorderu na tomhle stroji: php tests/bench/telemetry-overhead.php [počet SQL na request]
 *
 * Měří jednotlivé operace a „typický request" (N SQL + pár odchozích volání + flush přes UDP),
 * jednou s běžícím agentem (socket poslouchá) a jednou bez něj (datagram do prázdna).
 */

use LiquidMonitorConnector\Telemetry\OpcacheProbe;
use LiquidMonitorConnector\Telemetry\Recorder;
use LiquidMonitorConnector\Telemetry\TelemetryConfig;

require __DIR__ . '/../../vendor/autoload.php';

$queries = (int) ($argv[1] ?? 200);
$port = 45000 + \getmypid() % 10000;
$config = new TelemetryConfig(enabled: true, cli: true, port: $port, sampleRate: 0.1);

$bench = static function (string $label, int $iterations, Closure $callback): float {
	$callback();
	$start = \hrtime(true);

	for ($i = 0; $i < $iterations; $i++) {
		$callback();
	}

	$ns = (\hrtime(true) - $start) / $iterations;
	\printf("%-52s %10.0f ns  (%8.3f ms)\n", $label, $ns, $ns / 1e6);

	return $ns;
};

$sql = [];

for ($i = 0; $i < 40; $i++) {
	$sql[] = "SELECT `this`.* FROM `eshop_product` AS `this` WHERE `this`.`uuid` IN (:p{$i}) AND `this`.`hidden` = 0";
}

$request = static function (?TelemetryConfig $override = null) use ($config, $queries, $sql): void {
	Recorder::start($override ?? $config);
	Recorder::mark('startup');
	Recorder::setRoute('Eshop:Product:detail');
	Recorder::mark('presenter');

	for ($i = 0; $i < $queries; $i++) {
		Recorder::record('sql', $sql[$i % 40], 180_000 + $i);
	}

	for ($i = 0; $i < 3; $i++) {
		$start = Recorder::begin();
		Recorder::end('qi', 'UCLGetAllGoods_ex', $start);
	}

	Recorder::mark('send');
	Recorder::flush(200);
};

echo "PHP " . \PHP_VERSION . ", opcache " . (OpcacheProbe::read() !== null ? 'on' : 'off') . ", {$queries} SQL/request\n\n";

$bench('neaktivní Recorder::record()', 1_000_000, static fn () => Recorder::record('sql', 'SELECT 1', 1000));

Recorder::start(new TelemetryConfig(enabled: true, cli: true, port: $port, maxSpans: 1_000_000_000, maxKeysPerType: 1_000_000));
$bench('aktivní Recorder::record() (existující klíč)', 1_000_000, static fn () => Recorder::record('sql', 'SELECT 1', 1000));
$bench('aktivní begin() + end()', 1_000_000, static fn () => Recorder::end('qi', 'M', Recorder::begin()));
Recorder::reset();

$bench('OpcacheProbe::read()', 100_000, static fn () => OpcacheProbe::read());

$noAgent = $bench('celý request bez agenta', 2_000, $request);
$sampledConfig = new TelemetryConfig(enabled: true, cli: true, port: $port, sampleRate: 1.0);
$sampled = $bench('celý request s časovou osou (sampleRate 1)', 2_000, static fn () => $request($sampledConfig));

$server = \stream_socket_server('udp://127.0.0.1:' . $port, $errno, $errstr, \STREAM_SERVER_BIND);
\stream_set_blocking($server, false);
$withAgent = $bench('celý request s poslouchajícím socketem', 2_000, static function () use ($request, $server): void {
	$request();

	do {
		// vyprázdnit frontu, ať se buffer socketu nezaplní
		$datagram = @\stream_socket_recvfrom($server, 65535);
	} while ($datagram !== false && $datagram !== '');
});
\fclose($server);

Recorder::start($config);

for ($i = 0; $i < $queries; $i++) {
	Recorder::record('sql', $sql[$i % 40], 180_000 + $i);
}

$size = \strlen((string) \json_encode(Recorder::buildPayload(200)));
Recorder::reset();

\printf("\nvelikost datagramu bez spanů: %d B\nrozpočet 0,5 ms na request: %s\n", $size, \max($noAgent, $withAgent, $sampled) < 500_000 ? 'OK' : 'PŘEKROČEN');
