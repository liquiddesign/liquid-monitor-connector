<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry\Agent;

// phpcs:disable Generic.PHP.NoSilencedErrors.Discouraged -- agent musí přežít přechodné chyby socketu; výstup jde stejně do /dev/null

/**
 * Lokální sběrač datagramů recorderu (`vendor/bin/monitor-telemetry-agent`).
 *
 * Běží krátce (~65 s) a spouští ho každou minutu {@see AgentLauncher} z Crunz tasku monitor
 * workeru. Port otevírá s `SO_REUSEPORT`, takže se starý a nový agent krátce překrývají —
 * datagramy se neztrácí a po deployi nezůstane běžet starý kód. Minuty, které zasáhnou dva agenty,
 * vydají oba; data jsou sčitatelná a spojí se až při čtení.
 */
final class TelemetryAgent
{
	private const MAX_DATAGRAM = 65535;

	public function __construct(private Aggregator $aggregator, private Sink $sink)
	{
	}

	public function run(string $host, int $port, int $maxRuntime): int
	{
		$context = \stream_context_create(['socket' => ['so_reuseport' => true]]);
		$server = @\stream_socket_server('udp://' . $host . ':' . $port, $errno, $errstr, \STREAM_SERVER_BIND, $context);

		if ($server === false) {
			\fwrite(\STDERR, "monitor-telemetry-agent: cannot bind udp://{$host}:{$port} ({$errstr})\n");

			return 1;
		}

		\stream_set_blocking($server, false);
		$deadline = \time() + $maxRuntime;

		while (\time() < $deadline) {
			$read = [$server];
			$write = null;
			$except = null;

			if (@\stream_select($read, $write, $except, 1) > 0) {
				$this->receive($server);
			}

			$this->flushCompletedMinutes();
		}

		$this->receive($server);
		\fclose($server);
		$this->sink->write($this->aggregator->drain());

		return 0;
	}

	/**
	 * @param resource $server
	 */
	private function receive($server): void
	{
		for ($i = 0; $i < 10000; $i++) {
			$datagram = @\stream_socket_recvfrom($server, self::MAX_DATAGRAM);

			if ($datagram === false || $datagram === '') {
				return;
			}

			$this->aggregator->ingest($datagram);
		}
	}

	private function flushCompletedMinutes(): void
	{
		$completed = $this->aggregator->drain(\intdiv(\time(), 60) * 60);

		if ($completed === []) {
			return;
		}

		$this->sink->write($completed);
	}
}
