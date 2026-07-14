<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

/**
 * Mirrors `App\Models\Enums\CronConcurrencyEnum` on the monitor backend.
 * Sent as `cronConcurrencyMode` on the `schedule-job` payload.
 */
enum ConcurrencyModeType: string
{
	case Exclusive = 'exclusive';
	case Parallel = 'parallel';
	case FullyParallel = 'fully_parallel';
	case Independent = 'independent';
}
