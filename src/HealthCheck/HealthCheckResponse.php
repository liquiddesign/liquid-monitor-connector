<?php

namespace LiquidMonitorConnector\HealthCheck;

use Carbon\Carbon;
use Nette\Application\Responses\JsonResponse;

class HealthCheckResponse
{
	/**
	 * @param \LiquidMonitorConnector\HealthCheck\HealthCheckStatusEnum $status
	 * @param array<\LiquidMonitorConnector\HealthCheck\HealthCheckData> $data
	 */
	public function __construct(private HealthCheckStatusEnum $status, private array $data = [])
	{
	}

	public static function ok(): self
	{
		return new self(HealthCheckStatusEnum::OK);
	}

	public static function warning(): self
	{
		return new self(HealthCheckStatusEnum::WARNING);
	}

	public static function error(): self
	{
		return new self(HealthCheckStatusEnum::ERROR);
	}

	public static function critical(): self
	{
		return new self(HealthCheckStatusEnum::CRITICAL);
	}

	public function addData(HealthCheckData $data): self
	{
		$this->data[] = $data;

		if ($this->status->value < $data->getStatus()->value) {
			$this->setStatus($data->getStatus());
		}

		return $this;
	}

	public function setStatus(HealthCheckStatusEnum $status): void
	{
		$this->status = $status;
	}

	public function getResponse(): JsonResponse
	{
		$responseData = [
			'status' => $this->status->name,
			'statusTs' => Carbon::now()->toDateTimeString(),
		];

		foreach ($this->data as $data) {
			$responseData['data'][] = $data->toArray();
		}

		return new JsonResponse($responseData);
	}
}
