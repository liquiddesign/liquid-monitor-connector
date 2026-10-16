<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

use Nette\Utils\Strings;

/**
 * Process identity as `lqcron` checks it: pid + start time from `/proc/<pid>/stat`
 * (field 22, clock ticks since boot), so a recycled pid is not taken for the
 * original process (docs/v4-local-scheduler.md § 7.3).
 */
final class ProcessInfo
{
	public static function startTime(int $pid): int|null
	{
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a vanished process is an expected answer, not a warning
		$stat = @\file_get_contents('/proc/' . $pid . '/stat');

		if (!\is_string($stat)) {
			return null;
		}

		// The command name (field 2) is in parentheses and may contain spaces.
		$closing = \strrpos($stat, ')');

		if ($closing === false) {
			return null;
		}

		$fields = \explode(' ', Strings::trim(Strings::substring($stat, $closing + 2)));

		// Fields after the name start at field 3, so field 22 is index 19.
		return isset($fields[19]) && \is_numeric($fields[19]) ? (int) $fields[19] : null;
	}
}
