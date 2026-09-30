<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry;

interface Transport
{
	/**
	 * Odešle jeden datagram. Nesmí vyhodit výjimku ani čekat na odpověď.
	 */
	public function send(string $payload): void;
}
