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

		Recorder::setKeyNormalizer(self::TYPE, self::normalizeSql(...));
		$connection->setQueryObserver(static function (string $sql, int $durationNs, bool $error): void {
			Recorder::record(self::TYPE, $sql, $durationNs, $error);
		});
	}

	/**
	 * Literály → `?`, seznam `(?, ?, …)` → `(?+)`. StORM část hodnot vkládá přímo do SQL
	 * (`IN ('uuid1', 'uuid2')`), takže jeden dotaz by jinak měl stovky variant.
	 */
	public static function normalizeSql(string $sql): string
	{
		return \preg_replace(
			['/\'(?:[^\'\\\\]|\\\\.)*\'/', '/"(?:[^"\\\\]|\\\\.)*"/', '/\b\d+(?:\.\d+)?\b/', '/\(\s*\?(?:\s*,\s*\?)+\s*\)/'],
			['?', '?', '?', '(?+)'],
			$sql,
		) ?? $sql;
	}
}
