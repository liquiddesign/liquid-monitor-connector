<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry;

/**
 * Napojí recorder na `StORM\Connection::setQueryObserver()` (StORM 2.1+).
 *
 * Connector na StORM nezávisí: starší StORM bez observeru se tiše přeskočí.
 */
final class StormBridge
{
	public const TYPE = 'sql';

	public static function attach(object $connection, TelemetryConfig $config): void
	{
		if (!$config->storm || !$config->isActiveForCurrentSapi() || !\method_exists($connection, 'setQueryObserver')) {
			return;
		}

		$connection->setQueryObserver(static function (string $sql, int $durationNs, bool $error): void {
			Recorder::record(self::TYPE, $sql, $durationNs, $error);
		});
	}
}
