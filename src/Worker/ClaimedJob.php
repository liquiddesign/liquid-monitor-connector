<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

final class ClaimedJob
{
	/**
	 * @param array<mixed>|null $arguments
	 */
	public function __construct(
		public readonly int $jobId,
		public readonly int $jobLogId,
		public readonly string $cronCode,
		public readonly ?array $arguments,
		public readonly int $timeout,
		public readonly int $leaseSeconds,
		public readonly string $concurrencyMode,
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
