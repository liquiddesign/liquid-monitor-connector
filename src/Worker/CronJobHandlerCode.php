<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Nette\Utils\Strings;

/**
 * Maps a handler class name to the LQDeck cron code (must match schedule-job / admin cron code).
 *
 * {@see UpdateCkpFloatingPricesHandler} → {@code updateCkpFloatingPrices}
 */
final class CronJobHandlerCode
{
	/**
	 * @param class-string $class
	 */
	public static function fromClassName(string $class): string
	{
		if (!\class_exists($class)) {
			throw new \InvalidArgumentException(\sprintf('Cron job handler class "%s" does not exist.', $class));
		}

		$shortName = (new \ReflectionClass($class))->getShortName();

		if (!\str_ends_with($shortName, 'Handler')) {
			throw new \InvalidArgumentException(\sprintf(
				'Cron job handler class "%s" must end with "Handler".',
				$class,
			));
		}

		$baseName = Strings::substring($shortName, 0, Strings::length($shortName) - Strings::length('Handler'));

		if ($baseName === '') {
			throw new \InvalidArgumentException(\sprintf(
				'Cron job handler class "%s" has an empty cron code after stripping the Handler suffix.',
				$class,
			));
		}

		return Strings::firstLower($baseName);
	}
}
