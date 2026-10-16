<?php

declare(strict_types=1);

namespace LqcronE2e;

use LiquidMonitorConnector\Cron;
use LiquidMonitorConnector\Worker\CronJobHandler;
use LiquidMonitorConnector\Worker\PullCron;

#[PullCron(schedule: '*/5 * * * *', name: 'Import', timeout: 60)]
final class ImportHandler implements CronJobHandler
{
	public function __construct(private Cron $cron)
	{
	}

	public function execute(?array $arguments): void
	{
		echo "importing\n";
		$this->cron->progressJob(['done' => 1]);
		$this->cron->finishJob(['imported' => $arguments['count'] ?? 0]);
	}
}

#[PullCron(schedule: '0 3 * * *', repeatCount: 1)]
final class FlakyHandler implements CronJobHandler
{
	public function execute(?array $arguments): void
	{
		echo "password=hunter2 about to fail\n";

		throw new \RuntimeException('remote API down');
	}
}

#[PullCron(schedule: '0 4 * * *', after: ImportHandler::class)]
final class AfterImportHandler implements CronJobHandler
{
	public function execute(?array $arguments): void
	{
		echo "after import\n";
	}
}

final class Redactor implements \LiquidMonitorConnector\Scheduler\LogRedactor
{
	public function redact(string $log): string
	{
		return \str_replace('hunter2', '[redacted]', $log);
	}
}
