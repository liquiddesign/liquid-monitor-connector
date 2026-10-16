<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

/**
 * Optional extension of {@see CronJobHandler}: `lqcron` sends SIGTERM to a run
 * that timed out or was cancelled and gives `interrupt()` a chance to clean up
 * (release locks, close files) before SIGKILL follows after `killGraceSeconds`
 * (docs/v4-local-scheduler.md § 7.2). The run ends as `timeout` / `cancelled`
 * regardless of what `interrupt()` does.
 */
interface InterruptibleCronJobHandler extends CronJobHandler
{
	public function interrupt(): void;
}
