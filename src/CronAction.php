<?php

namespace LiquidMonitorConnector;

abstract class CronAction
{
	public function __construct(protected readonly Cron $cron)
	{
	}

	public function startJob(int $jobId): void
	{
		$this->cron->startJob(jobId: $jobId);
	}
}
