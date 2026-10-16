<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler\Exceptions;

/**
 * The local scheduler database cannot be used from this PHP process: it does not
 * exist (a web node without the scheduler, § 4.1), its schema is newer than this
 * code can write (§ 12.1), or SQLite stayed locked past `busy_timeout`.
 */
final class SchedulerUnavailableException extends SchedulingFailedException
{
}
