<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Triage;

use GuzzleHttp\Client;
use Nette\Utils\Json;

final class MonitorClient
{
	private Client $client;

	public function __construct(string $monitorUrl, private readonly string $apiKey, int $timeoutSeconds = 30,)
	{
		$this->client = new Client([
			'base_uri' => \rtrim($monitorUrl, '/') . '/',
			'headers' => [
				'X-Api-Key' => $this->apiKey,
				'Accept' => 'application/json',
			],
			'timeout' => $timeoutSeconds,
		]);
	}

	/**
	 * Returned shape (server-defined, see /api/triage/worker/poll):
	 *   - paused: bool
	 *   - tasks: list<array<string,mixed>> (only when not paused)
	 *   - context_sources: list<array<string,mixed>> (only when not paused)
	 *   - agent: array{key: string, label?: string, binary?: string, model?: string}|null
	 *   - lease_minutes?: int
	 *   - worker_id?: string
	 * @param array<int, int> $inFlight
	 * @return array<string, mixed>
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function pollWork(string $workerId, int $capacity, array $inFlight): array
	{
		$response = $this->client->post('api/triage/worker/poll', [
			'json' => [
				'worker_id' => $workerId,
				'capacity' => $capacity,
				'in_flight' => $inFlight,
			],
		]);

		/** @var array<string, mixed> $decoded */
		$decoded = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);

		return $decoded;
	}

	/**
	 * Server expects at least 'category' in $payload. Other fields used by the
	 * monitor UI: draft_response_md, provider, model, context_sources, tools_called,
	 * input_tokens, output_tokens, estimated_cost_usd.
	 * @param array<string, mixed> $payload
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function postResult(int $taskId, array $payload): void
	{
		$this->client->post('api/triage/results', [
			'json' => \array_merge(['triage_task_id' => $taskId], $payload),
		]);
	}

	/**
	 * @param array<string, mixed> $patch
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function patchTask(int $taskId, array $patch): void
	{
		$this->client->patch('api/triage/tasks/' . $taskId, ['json' => $patch]);
	}
}
