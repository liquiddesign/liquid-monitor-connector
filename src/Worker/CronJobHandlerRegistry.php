<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

final class CronJobHandlerRegistry
{
	/**
	 * @param array<string, \LiquidMonitorConnector\Worker\CronJobHandler> $handlers
	 */
	public function __construct(private readonly array $handlers)
	{
	}

	public function get(string $cronCode): CronJobHandler
	{
		if (!isset($this->handlers[$cronCode])) {
			throw new \RuntimeException(\sprintf('No cron job handler registered for code "%s".', $cronCode));
		}

		return $this->handlers[$cronCode];
	}

	/**
	 * @return array<string, \LiquidMonitorConnector\Worker\CronJobHandler>
	 */
	public function all(): array
	{
		return $this->handlers;
	}
}
