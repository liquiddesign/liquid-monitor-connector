<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

interface WorkerClientContract
{
	public function claimJobs(string $workerId, int $maxJobs, int $leaseSeconds): ClaimJobsResult;

	public function heartbeatJob(int $jobId): void;

	/**
	 * @param array<mixed>|null $data
	 */
	public function finishJob(int $jobId, ?array $data = null): void;

	/**
	 * @param array<mixed>|null $data
	 */
	public function failJob(int $jobId, ?array $data = null): void;

	/**
	 * @param array<mixed>|null $data
	 */
	public function progressJob(int $jobId, ?array $data = null): void;
}
