<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Attribute;

/**
 * Declares a code-managed pull-model cron on a {@see CronJobHandler} class.
 * The monitor is the runtime (it schedules jobs the worker claims), but the
 * code is the source of truth for the cron's metadata: `Cron::schedulePullJob()`
 * reads this attribute and syncs (name, description, repeatCount,
 * concurrencyMode, timeout, maxQueueSize, timing) into the monitor on every call.
 *
 * {@see UpdateCkpFloatingPricesHandler}:
 * ```php
 * #[PullCron(schedule: '*\/5 * * * *')]
 * final class UpdateCkpFloatingPricesHandler implements CronJobHandler
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PullCron
{
	/**
	 * @param string $schedule Cron expression (e.g. `*\/5 * * * *`), synced as `cronTiming`.
	 * @param string|null $name Synced as `cronName`; falls back to the cron code when null.
	 * @param int $repeatCount Synced as `cronRepeatCount`.
	 * @param \LiquidMonitorConnector\Worker\ConcurrencyModeType|null $concurrencyMode Synced as `cronConcurrencyMode`; null lets the monitor
	 *   fall back to its own default (exclusive).
	 * @param string|null $description Synced as `cronDescription`.
	 * @param int|null $timeout Synced as `cronTimeout`.
	 * @param int|null $maxQueueSize Synced as `cronMaxQueueSize`.
	 */
	public function __construct(
		public readonly string $schedule,
		public readonly string|null $name = null,
		public readonly int $repeatCount = 0,
		public readonly ConcurrencyModeType|null $concurrencyMode = null,
		public readonly string|null $description = null,
		public readonly int|null $timeout = null,
		public readonly int|null $maxQueueSize = null,
	) {
	}
}
