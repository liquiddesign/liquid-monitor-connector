<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry;

/**
 * Fire-and-forget UDP na lokálního agenta. Nečeká na potvrzení; když agent neběží, zápis
 * selže okamžitě a potichu — aplikace o výpadku telemetrie neví.
 */
final class UdpTransport implements Transport
{
	public function __construct(private string $host, private int $port)
	{
	}

	public function send(string $payload): void
	{
		// phpcs:disable Generic.PHP.NoSilencedErrors.Discouraged -- odmítnutý datagram (agent neběží) hlásí notice; ten se nesmí dostat do Tracy ani do odpovědi
		$socket = @\stream_socket_client('udp://' . $this->host . ':' . $this->port, $errno, $errstr, 0.0);

		if ($socket === false) {
			return;
		}

		@\fwrite($socket, $payload);
		@\fclose($socket);
		// phpcs:enable
	}
}
