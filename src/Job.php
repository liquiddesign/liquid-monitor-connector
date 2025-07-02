<?php

namespace LiquidMonitorConnector;

final class Job
{
	public function __construct(private readonly Connector2 $connector2, private int $cronId,)
	{
	}

	/**
	 * @param array<mixed>|null $arguments
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \LiquidMonitorConnector\Exceptions\LiquidMonitorDisabledException
	 */
	public function scheduleJob(
		string $cronId,
		string|null $cronName = null,
		int $cronRepeatCount = 0,
		bool $cronCanRunConcurrently = false,
		bool $cronCanRunConcurrentlyCron = false,
		string|null $cronDescription = null,
		int|null $cronTimeout = null,
		bool $createIfNotExists = true,
		array|null $arguments = null,
	): void {
		$params = [
			'cronId' => $cronId,
			'timeout' => (int) \ini_get('max_execution_time'),
			'cronName' => $cronName,
			'cronUrl' => $this->httpRequest->getUrl(),
			'cronRepeatCount' => $cronRepeatCount,
			'cronCanRunConcurrently' => $cronCanRunConcurrently,
			'cronCanRunConcurrentlyCron' => $cronCanRunConcurrentlyCron,
			'cronDescription' => $cronDescription,
			'cronTimeout' => $cronTimeout,
			'createIfNotExists' => $createIfNotExists,
			'arguments' => $arguments ? \serialize($arguments) : null,
		];
		$this->send($this->getUrl() . self::JOB_SCHEDULE_ENDPOINT, $params, true);
	}
}
