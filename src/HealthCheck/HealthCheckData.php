<?php

namespace LiquidMonitorConnector\HealthCheck;

class HealthCheckData
{
	public function __construct(
		private readonly string $name,
		private readonly mixed $value,
		private readonly HealthCheckStatusEnum $status = HealthCheckStatusEnum::OK,
		private readonly string|null $description = null,
	) {
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getStatus(): HealthCheckStatusEnum
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
			'status' => $this->status->name,
			'description' => $this->description,
		];
	}
}
