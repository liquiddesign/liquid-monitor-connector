<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry\Agent;

interface Sink
{
	/**
	 * @param list<array<string, mixed>> $minutes výstup {@see Aggregator::drain()}
	 */
	public function write(array $minutes): void;
}
