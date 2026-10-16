<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

/**
 * Time helpers matching the contract (docs/v4-contract.md): unix milliseconds
 * in SQLite, ISO 8601 UTC with milliseconds in JSON.
 */
final class Clock
{
	public static function nowMs(): int
	{
		return (int) \floor(\microtime(true) * 1000);
	}

	public static function iso(int $milliseconds): string
	{
		$seconds = \intdiv($milliseconds, 1000);
		$millis = $milliseconds % 1000;

		return \gmdate('Y-m-d\TH:i:s', $seconds) . \sprintf('.%03d+00:00', $millis);
	}

	public static function isoOrNull(int|null $milliseconds): string|null
	{
		return $milliseconds === null ? null : self::iso($milliseconds);
	}
}
