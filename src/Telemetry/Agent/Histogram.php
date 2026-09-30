<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry\Agent;

/**
 * Řídký histogram s logaritmickými buckety (poměr 1,1 → chyba percentilu < 10 %, typicky ~5 %).
 *
 * Histogramy se dají sčítat, takže data dvou překrývajících se agentů (nebo víc serverů) se prostě
 * sečtou — percentily se počítají až nad součtem.
 */
final class Histogram
{
	public const BASE_MS = 0.1;

	public const RATIO = 1.1;

	public static function index(float $milliseconds): int
	{
		if ($milliseconds <= self::BASE_MS) {
			return 0;
		}

		return (int) \ceil(\log($milliseconds / self::BASE_MS) / \log(self::RATIO));
	}

	public static function upperBound(int $index): float
	{
		return self::BASE_MS * self::RATIO ** $index;
	}

	/**
	 * @param array<int, int> $histogram
	 */
	public static function add(array &$histogram, float $milliseconds, int $count = 1): void
	{
		$index = self::index($milliseconds);
		$histogram[$index] = ($histogram[$index] ?? 0) + $count;
	}

	/**
	 * @param array<int, int> $left
	 * @param array<int, int> $right
	 * @return array<int, int>
	 */
	public static function merge(array $left, array $right): array
	{
		foreach ($right as $index => $count) {
			$left[$index] = ($left[$index] ?? 0) + $count;
		}

		return $left;
	}

	/**
	 * @param array<int, int> $histogram
	 * @param float $quantile 0–1
	 */
	public static function percentile(array $histogram, float $quantile): ?float
	{
		$total = \array_sum($histogram);

		if ($total === 0) {
			return null;
		}

		\ksort($histogram);
		$target = \max(1, (int) \ceil($quantile * $total));
		$seen = 0;

		foreach ($histogram as $index => $count) {
			$seen += $count;

			if ($seen >= $target) {
				return \round(self::upperBound($index), 3);
			}
		}

		return \round(self::upperBound((int) \array_key_last($histogram)), 3);
	}
}
