<?php

namespace LiquidMonitorConnector;

abstract class CronJob
{
	public function __construct(protected readonly LiquidMonitorConnector $connector)
	{
	}

	protected abstract function getCronCode(): string;

	/**
	 * @param array<mixed> $arguments
	 */
	protected abstract function run(array $arguments): void;
}
