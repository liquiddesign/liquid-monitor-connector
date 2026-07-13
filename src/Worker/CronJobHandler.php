<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

interface CronJobHandler
{
	/**
	 * @param array<mixed>|null $arguments Custom parameters from the monitor job queue.
	 */
	public function execute(?array $arguments): void;
}
