<?php

namespace LiquidMonitorConnector\HealthCheck;

/**
 * Stav health checku. Do 3.x to byl `enum HealthCheckStatusEnum: int`; kvůli PHP 8.0 je to od 4.0 třída
 * s konstantami a hodnotou je přímo `int` (`HealthCheckStatusEnum::OK === 0`).
 *
 * Náhrady za enum: `$status->value` → `$status`, `$status->name` → `HealthCheckStatusEnum::name($status)`,
 * `::from()` / `::tryFrom()` / `::cases()` zůstávají (vrací int, resp. `array<string, int>`).
 */
final class HealthCheckStatusEnum
{
	public const INFO = -1;
	public const OK = 0;
	public const WARNING = 1;
	public const ERROR = 2;
	public const CRITICAL = 3;

	private const NAMES = [
		self::INFO => 'INFO',
		self::OK => 'OK',
		self::WARNING => 'WARNING',
		self::ERROR => 'ERROR',
		self::CRITICAL => 'CRITICAL',
	];

	private function __construct()
	{
	}

	/**
	 * @throws \ValueError neznámá hodnota (stejně jako `BackedEnum::from()`)
	 */
	public static function from(int $value): int
	{
		if (!isset(self::NAMES[$value])) {
			throw new \ValueError($value . ' is not a valid backing value for ' . self::class);
		}

		return $value;
	}

	public static function tryFrom(int $value): ?int
	{
		return isset(self::NAMES[$value]) ? $value : null;
	}

	/**
	 * Název stavu (`'OK'`, `'WARNING'`, …) — náhrada za `$status->name`.
	 * @throws \ValueError neznámá hodnota
	 */
	public static function name(int $value): string
	{
		return self::NAMES[self::from($value)];
	}

	/**
	 * @return array<string, int> název => hodnota, v pořadí od nejmírnějšího stavu
	 */
	public static function cases(): array
	{
		return \array_flip(self::NAMES);
	}
}
