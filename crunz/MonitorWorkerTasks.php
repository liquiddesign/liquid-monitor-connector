<?php

declare(strict_types=1);

/**
 * Universal Crunz schedule for the LQDeck pull-model monitor worker.
 *
 * @deprecated since connector 4.0, removed in the next major version. It starts only
 * the worker — schedules live in `#[PullCron]` and are enqueued by
 * `monitor-worker enqueue-due` (or `lqcron tick`), one crontab line each
 * (docs/v4-local-scheduler.md § 5.0).
 *
 * Host project (one line in tasks/MonitorWorkerTasks.php):
 *   return require dirname(__DIR__) . '/vendor/liquiddesign/liquid-monitor-connector/crunz/MonitorWorkerTasks.php';
 */

use Crunz\Schedule;
use LiquidMonitorConnector\Worker\MonitorWorkerLauncher;

$schedule = new Schedule();

$schedule
	->run(static function (): void {
		MonitorWorkerLauncher::runNetteAuto(55);
	})
	->description('LQDeck pull-model monitor worker (claim + CLI handlers)')
	->everyMinute()
	->preventOverlapping();

return $schedule;
