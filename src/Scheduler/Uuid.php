<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

use Nette\Utils\Strings;

/**
 * Time-ordered UUID v7 (RFC 9562) — ids of jobs, runs and outbox events.
 */
final class Uuid
{
	public static function v7(int|null $milliseconds = null): string
	{
		$milliseconds ??= Clock::nowMs();
		$time = \sprintf('%012x', $milliseconds);
		$random = \bin2hex(\random_bytes(10));
		$variant = \dechex(0x8 | (\hexdec($random[3]) & 0x3));

		return \sprintf(
			'%s-%s-7%s-%s%s-%s',
			Strings::substring($time, 0, 8),
			Strings::substring($time, 8, 4),
			Strings::substring($random, 0, 3),
			$variant,
			Strings::substring($random, 4, 3),
			Strings::substring($random, 7, 12),
		);
	}
}
