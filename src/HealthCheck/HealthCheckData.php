<?php

namespace LiquidMonitorConnector\HealthCheck;

class HealthCheckData
{
	private int $status;

	/**
	 * @param int $status jedna z konstant {@see HealthCheckStatusEnum}
	 * @throws \ValueError neznámý stav
	 */
	public function __construct(
		private string $name,
		private mixed $value,
		int $status = HealthCheckStatusEnum::OK,
		private string|null $description = null,
	) {
		$this->status = HealthCheckStatusEnum::from($status);
	}

	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @return int jedna z konstant {@see HealthCheckStatusEnum}
	 */
	public function getStatus(): int
	{
		return $this->status;
	}

	/**
	 * @return array<mixed>
	 */
	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'value' => $this->value,
			'status' => HealthCheckStatusEnum::name($this->status),
			'description' => $this->description,
		];
	}
}
