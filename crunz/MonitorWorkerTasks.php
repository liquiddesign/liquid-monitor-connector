<?php

declare(strict_types=1);

/**
 * Universal Crunz schedule for the LQDeck pull-model monitor worker.
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
