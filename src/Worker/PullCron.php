<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Attribute;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;

/**
 * Declares a code-managed cron on a {@see CronJobHandler} class. The attribute is
 * the **only** source of the cron's schedule (docs/v4-local-scheduler.md § 5.0):
 * `monitor-worker enqueue-due` (without `lqcron`) and `lqcron tick` (with it) plan
 * runs from it, and `Cron::schedulePullJob()` syncs its metadata into the monitor.
 *
 * {@see UpdateCkpFloatingPricesHandler}:
 * ```php
 * #[PullCron(
 *     schedule: '*\/30 * * * *',
 *     schedules: ['test' => '15 *\/4 * * *'],
 *     environments: ['production', 'test'],
 * )]
 * final class UpdateCkpFloatingPricesHandler implements CronJobHandler
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PullCron
{
	public const MISSED_SKIP = 'skip';
	public const MISSED_ONCE = 'once';

	/**
	 * Separator of several expressions in one schedule string (export, `cronTiming`
	 * in LQDeck). A pipe never occurs in a cron expression.
	 */
	public const EXPRESSION_SEPARATOR = ' | ';

	/**
	 * @param string|list<string> $schedule Cron expression (e.g. `*\/5 * * * *`), or several when one expression
	 *   cannot describe the schedule (`['0 13 * * *', '15 23 * * *']`); used in every environment not listed in `$schedules`.
	 * @param string|null $name Synced as `cronName`; falls back to the cron code when null.
	 * @param int $repeatCount Retries after a failed run: N means N+1 attempts in total.
	 * @param string|null $concurrencyMode One of the {@see ConcurrencyModeType} constants; null means exclusive.
	 * @param string|null $description Synced as `cronDescription`.
	 * @param int|null $timeout Seconds; null means 1000 s, 0 means no limit (local scheduler only).
	 * @param int|null $maxQueueSize Null means 10.
	 * @param array<string, string|list<string>> $schedules Per-environment override of `$schedule`, keyed by `scheduler.environment`.
	 * @param list<string>|null $environments The cron is scheduled only in these environments; null = everywhere,
	 *   `[]` = nowhere (on demand only). It can still be enqueued on demand (application, CLI, LQDeck) elsewhere.
	 * @param list<string> $resources Resource groups the run occupies (local scheduler, `scheduler.resources`).
	 * @param class-string|null $after Enqueue this cron after the given handler's cron finishes successfully (local scheduler).
	 * @param string $missed What to do with an occurrence the scheduler could not plan in time (server down):
	 *   `skip` (default) or `once` (local scheduler).
	 * @param int $missedGraceMinutes How late an occurrence still runs as "on time" (local scheduler).
	 */
	public function __construct(
		public string|array $schedule,
		public string|null $name = null,
		public int $repeatCount = 0,
		public string|null $concurrencyMode = null,
		public string|null $description = null,
		public int|null $timeout = null,
		public int|null $maxQueueSize = null,
		public array $schedules = [],
		public array|null $environments = null,
		public array $resources = [],
		public string|null $after = null,
		public string $missed = self::MISSED_SKIP,
		public int $missedGraceMinutes = 5,
	) {
		if ($concurrencyMode !== null) {
			ConcurrencyModeType::from($concurrencyMode);
		}

		if (!Arrays::contains([self::MISSED_SKIP, self::MISSED_ONCE], $missed)) {
			throw new \InvalidArgumentException(\sprintf('PullCron "missed" must be "skip" or "once", "%s" given.', $missed));
		}
	}

	/**
	 * Effective schedule for an environment as one string (several expressions
	 * joined by {@see EXPRESSION_SEPARATOR}), or null when the cron is not
	 * scheduled there at all.
	 */
	public function scheduleFor(string|null $environment): string|null
	{
		$expressions = $this->expressionsFor($environment);

		return $expressions === [] ? null : \implode(self::EXPRESSION_SEPARATOR, $expressions);
	}

	/**
	 * Effective cron expressions for an environment; empty when not scheduled there.
	 * @return list<string>
	 */
	public function expressionsFor(string|null $environment): array
	{
		if (!$this->isScheduledIn($environment)) {
			return [];
		}

		$schedule = $environment !== null && isset($this->schedules[$environment]) ? $this->schedules[$environment] : $this->schedule;

		return self::normalizeExpressions($schedule);
	}

	/**
	 * Every expression the attribute declares, in any environment (for validation).
	 * @return list<string>
	 */
	public function allExpressions(): array
	{
		$expressions = self::normalizeExpressions($this->schedule);

		foreach ($this->schedules as $schedule) {
			$expressions = \array_merge($expressions, self::normalizeExpressions($schedule));
		}

		return \array_values(\array_unique($expressions));
	}

	public function isScheduledIn(string|null $environment): bool
	{
		if ($this->environments === null) {
			return true;
		}

		return $environment !== null && Arrays::contains($this->environments, $environment);
	}

	/**
	 * Where the effective schedule came from: `schedule`, `schedules.<env>`, or
	 * `environments` when the environment switched the cron off.
	 */
	public function scheduleSourceFor(string|null $environment): string
	{
		if (!$this->isScheduledIn($environment)) {
			return 'environments';
		}

		if ($environment !== null && isset($this->schedules[$environment])) {
			return 'schedules.' . $environment;
		}

		return 'schedule';
	}

	/**
	 * Accepts a single expression, a list, or an already joined schedule string.
	 * @param string|array<mixed> $schedule
	 * @return list<string>
	 */
	public static function normalizeExpressions(string|array $schedule): array
	{
		$parts = \is_array($schedule) ? $schedule : \explode('|', $schedule);
		$expressions = [];

		foreach ($parts as $part) {
			$expression = \is_string($part) ? Strings::trim($part) : '';

			if ($expression === '') {
				continue;
			}

			$expressions[] = $expression;
		}

		return $expressions;
	}
}
