<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

final class ClaimJobsResult
{
	/**
	 * @param list<\LiquidMonitorConnector\Worker\ClaimedJob> $jobs
	 */
	public function __construct(
		public readonly array $jobs,
		public readonly int $pendingJobs,
		public readonly int $pollAfterSeconds,
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function fromArray(array $payload): self
	{
		$jobs = [];

		foreach ($payload['jobs'] ?? [] as $jobPayload) {
			if (!\is_array($jobPayload)) {
				continue;
			}

			$jobs[] = ClaimedJob::fromArray($jobPayload);
		}

		return new self(
			jobs: $jobs,
			pendingJobs: (int) ($payload['pendingJobs'] ?? 0),
			pollAfterSeconds: (int) ($payload['pollAfterSeconds'] ?? 15),
		);
	}
}
