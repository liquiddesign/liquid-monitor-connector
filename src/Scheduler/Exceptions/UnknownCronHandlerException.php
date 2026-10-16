<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler\Exceptions;

/**
 * The class is not a cron handler the scheduler can run (no `#[PullCron]`).
 */
final class UnknownCronHandlerException extends SchedulingFailedException
{
}
