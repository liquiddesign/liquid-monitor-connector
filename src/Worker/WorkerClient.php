<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use LiquidMonitorConnector\Version;
use Nette\Utils\Json;
use Psr\Http\Message\ResponseInterface;

final class WorkerClient implements WorkerClientContract
{
	private Client $client;

	public function __construct(
		string $connectorUrl,
		private readonly string $apiKey,
		?Client $client = null,
		private readonly int $timeoutSeconds = 30,
		private readonly bool|string $verifyTls = true,
	) {
		$connectorUrl = \rtrim($connectorUrl, '/');

		$this->client = $client ?? new Client([
			'base_uri' => $connectorUrl . '/',
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
				Version::HEADER_NAME => Version::CURRENT,
			],
			'timeout' => $this->timeoutSeconds,
			'verify' => $verifyTls,
			'http_errors' => true,
		]);
	}

	public function claimJobs(string $workerId, int $maxJobs, int $leaseSeconds): ClaimJobsResult
	{
		$response = $this->postWithRetry('claim-jobs', [
			'apiKey' => $this->apiKey,
			'workerId' => $workerId,
			'maxJobs' => $maxJobs,
			'leaseSeconds' => $leaseSeconds,
		]);

		/** @var array<string, mixed> $decoded */
		$decoded = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);

		return ClaimJobsResult::fromArray($decoded);
	}

	public function heartbeatJob(int $jobId): void
	{
		$this->postWithRetry('heartbeat-job', [
			'apiKey' => $this->apiKey,
			'jobId' => $jobId,
		]);
	}

	public function finishJob(int $jobId, ?array $data = null): void
	{
		$this->postWithRetry('finish-job', $this->jobPayload($jobId, $data));
	}

	public function failJob(int $jobId, ?array $data = null): void
	{
		$this->postWithRetry('fail-job', $this->jobPayload($jobId, $data));
	}

	public function progressJob(int $jobId, ?array $data = null): void
	{
		$this->postWithRetry('progress-job', $this->jobPayload($jobId, $data));
	}

	/**
	 * @param array<mixed>|null $data
	 * @return array<string, mixed>
	 */
	private function jobPayload(int $jobId, ?array $data): array
	{
		$payload = [
			'apiKey' => $this->apiKey,
			'jobId' => $jobId,
		];

		if ($data !== null) {
			$payload['data'] = $data;
		}

		$memoryUsage = (int) (\memory_get_peak_usage(true) / 1024 / 1024);

		if ($memoryUsage > 0) {
			$payload['ram'] = $memoryUsage;
		}

		return $payload;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function postWithRetry(string $endpoint, array $payload): ResponseInterface
	{
		$attempts = 0;
		$maxAttempts = 4;
		$delaySeconds = 1;
		$lastException = null;

		while ($attempts < $maxAttempts) {
			$attempts++;

			try {
				$response = $this->client->post($endpoint, ['json' => $payload]);

				if ($response->getStatusCode() >= 500) {
					if ($attempts >= $maxAttempts) {
						return $response;
					}

					\sleep($delaySeconds);
					$delaySeconds *= 2;

					continue;
				}

				return $response;
			} catch (TransferException $e) {
				$lastException = $e;

				if ($attempts >= $maxAttempts) {
					break;
				}

				\sleep($delaySeconds);
				$delaySeconds *= 2;
			} catch (GuzzleException $e) {
				throw $e;
			}
		}

		if ($lastException !== null) {
			throw $lastException;
		}

		return new Response(500);
	}
}
