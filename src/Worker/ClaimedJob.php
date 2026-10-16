<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

final class ClaimedJob
{
	/**
	 * @param array<mixed>|null $arguments
	 */
	public function __construct(
		public int $jobId,
		public int $jobLogId,
		public string $cronCode,
		public ?array $arguments,
		public int $timeout,
		public int $leaseSeconds,
		public string $concurrencyMode,
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function fromArray(array $payload): self
	{
		$arguments = $payload['arguments'] ?? null;

		return new self(
			jobId: (int) ($payload['jobId'] ?? 0),
			jobLogId: (int) ($payload['jobLogId'] ?? 0),
			cronCode: (string) ($payload['cronCode'] ?? ''),
			arguments: \is_array($arguments) ? $arguments : null,
			timeout: (int) ($payload['timeout'] ?? 0),
			leaseSeconds: (int) ($payload['leaseSeconds'] ?? 60),
			concurrencyMode: (string) ($payload['concurrencyMode'] ?? ''),
		);
	}
}
