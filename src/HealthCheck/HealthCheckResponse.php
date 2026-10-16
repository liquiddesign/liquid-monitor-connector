<?php

namespace LiquidMonitorConnector\HealthCheck;

use Carbon\Carbon;
use Nette\Application\Responses\JsonResponse;

class HealthCheckResponse
{
	private int $status;

	/**
	 * @param int $status jedna z konstant {@see HealthCheckStatusEnum}
	 * @param array<\LiquidMonitorConnector\HealthCheck\HealthCheckData> $data
	 * @throws \ValueError neznámý stav
	 */
	public function __construct(int $status, private array $data = [])
	{
		$this->status = HealthCheckStatusEnum::from($status);
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

		if ($this->status < $data->getStatus()) {
			$this->setStatus($data->getStatus());
		}

		return $this;
	}

	/**
	 * @param int $status jedna z konstant {@see HealthCheckStatusEnum}
	 * @throws \ValueError neznámý stav
	 */
	public function setStatus(int $status): void
	{
		$this->status = HealthCheckStatusEnum::from($status);
	}

	public function getResponse(): JsonResponse
	{
		$responseData = [
			'status' => HealthCheckStatusEnum::name($this->status),
			'statusTs' => Carbon::now()->toDateTimeString(),
		];

		foreach ($this->data as $data) {
			$responseData['data'][] = $data->toArray();
		}

		return new JsonResponse($responseData);
	}
}
