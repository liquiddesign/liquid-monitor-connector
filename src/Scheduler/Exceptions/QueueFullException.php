<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler\Exceptions;

/**
 * The cron already has `maxQueueSize` jobs waiting (delayed ones included).
 */
final class QueueFullException extends SchedulingFailedException
{
	public function __construct(public string $cronCode, public int $queueSize, public int $maxQueueSize)
	{
		parent::__construct(\sprintf(
			'Local queue of cron "%s" is full (%d of %d jobs waiting).',
			$cronCode,
			$queueSize,
			$maxQueueSize,
		));
	}
}
