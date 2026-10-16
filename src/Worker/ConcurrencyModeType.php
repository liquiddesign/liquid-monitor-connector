<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Nette\Utils\Arrays;

/**
 * Mirrors `App\Models\Enums\CronConcurrencyEnum` on the monitor backend.
 *
 * A class with string constants rather than an enum so the connector runs on
 * PHP 8.0 (connector 4.0). `#[PullCron(concurrencyMode: ConcurrencyModeType::Exclusive)]`
 * reads the same as before; the value is now the plain string (`'exclusive'`),
 * so `->value` is no longer needed.
 */
final class ConcurrencyModeType
{
	// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase -- the names of the former enum cases, so `ConcurrencyModeType::Exclusive` keeps working
	public const Exclusive = 'exclusive';
	public const Parallel = 'parallel';
	public const FullyParallel = 'fully_parallel';
	public const Independent = 'independent';
	// phpcs:enable

	private function __construct()
	{
	}

	/**
	 * @return list<string>
	 */
	public static function cases(): array
	{
		return [self::Exclusive, self::Parallel, self::FullyParallel, self::Independent];
	}

	public static function tryFrom(string $value): string|null
	{
		return Arrays::contains(self::cases(), $value) ? $value : null;
	}

	public static function from(string $value): string
	{
		$mode = self::tryFrom($value);

		if ($mode === null) {
			throw new \ValueError(\sprintf('"%s" is not a valid concurrency mode (%s).', $value, \implode(', ', self::cases())));
		}

		return $mode;
	}
}
