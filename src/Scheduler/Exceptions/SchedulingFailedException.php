<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler\Exceptions;

use GuzzleHttp\Exception\GuzzleException;

/**
 * Common parent of every failure to enqueue a job into the local scheduler.
 *
 * It implements {@see GuzzleException} on purpose: callers written for the v3
 * remote queue catch `GuzzleException` (429 full queue, monitor outage), and they
 * must keep catching the local equivalents without a code change
 * (docs/v4-local-scheduler.md § 9).
 */
class SchedulingFailedException extends \RuntimeException implements GuzzleException
{
}
