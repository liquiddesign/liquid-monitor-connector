<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use GuzzleHttp\Client;
use Nette\Utils\Json;

final class MonitorClient
{
	private Client $client;

	public function __construct(string $monitorUrl, private readonly string $apiKey, int $timeoutSeconds = 60)
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
	 * @return array<string, mixed>
	 */
	public function globalPause(): array
	{
		$response = $this->client->get('api/orchestrator/global-pause');

		/** @var array<string, mixed> $decoded */
		$decoded = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);

		return $decoded;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listSessionsWithPendingMessages(): array
	{
		return $this->listSessions(['has_pending_messages' => true]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listSessionsNeedingWorktreeCleanup(): array
	{
		return $this->listSessions(['needs_worktree_cleanup' => true]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listRunningSessions(): array
	{
		return $this->listSessions(['state' => 'running']);
	}

	/**
	 * Active-session counts are server-authoritative — the worker no longer reports
	 * them. A null capacity lets the monitor default to max_concurrent_sessions.
	 * @param array<int, int> $inFlight
	 * @return array<string, mixed>
	 */
	public function poll(string $workerId, ?int $capacity, array $inFlight = []): array
	{
		$payload = [
			'worker_id' => $workerId,
			'in_flight' => $inFlight,
		];

		if ($capacity !== null) {
			$payload['capacity'] = $capacity;
		}

		$response = $this->client->post('api/orchestrator/worker/poll', [
			'json' => $payload,
		]);

		/** @var array<string, mixed> $decoded */
		$decoded = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);

		return $decoded;
	}

	public function nextTurnNumber(int $sessionId): int
	{
		$response = $this->client->get('api/orchestrator/turns', [
			'query' => ['agent_session_id' => $sessionId],
		]);

		/** @var array{data?: array<int, array<string, mixed>>} $decoded */
		$decoded = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);
		$turns = $decoded['data'] ?? [];
		$max = 0;

		foreach ($turns as $turn) {
			$num = (int) ($turn['turn_number'] ?? 0);
			$max = \max($max, $num);
		}

		return $max + 1;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function createSession(array $payload): array
	{
		$response = $this->client->post('api/orchestrator/sessions', ['json' => $payload]);

		/** @var array<string, mixed> $decoded */
		$decoded = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function createMessage(array $payload): array
	{
		$response = $this->client->post('api/orchestrator/messages', ['json' => $payload]);

		/** @var array<string, mixed> $decoded */
		$decoded = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $patch
	 */
	public function patchMessage(int $messageId, array $patch): void
	{
		$this->client->patch('api/orchestrator/messages/' . $messageId, ['json' => $patch]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listMessages(int $sessionId, ?string $deliveryStatus = null): array
	{
		$query = ['agent_session_id' => $sessionId];

		if ($deliveryStatus !== null) {
			$query['delivery_status'] = $deliveryStatus;
		}

		$response = $this->client->get('api/orchestrator/messages', ['query' => $query]);

		/** @var array{data?: array<int, array<string, mixed>>} $decoded */
		$decoded = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);

		return $decoded['data'] ?? [];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function createTurn(array $payload): array
	{
		$response = $this->client->post('api/orchestrator/turns', ['json' => $payload]);

		/** @var array<string, mixed> $decoded */
		$decoded = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function createEvent(array $payload): void
	{
		$this->client->post('api/orchestrator/events', ['json' => $payload]);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function postResult(int $taskId, array $payload): void
	{
		$this->client->post('api/triage/results', [
			'json' => \array_merge(['triage_task_id' => $taskId], $payload),
		]);
	}

	/**
	 * @param array<string, mixed> $patch
	 */
	public function patchTask(int $taskId, array $patch): void
	{
		$this->client->patch('api/triage/tasks/' . $taskId, ['json' => $patch]);
	}

	/**
	 * @param array<string, mixed> $patch
	 */
	public function patchSession(int $sessionId, array $patch): void
	{
		$this->client->patch('api/orchestrator/sessions/' . $sessionId, ['json' => $patch]);
	}

	public function archiveSession(int $sessionId): void
	{
		$this->client->post('api/orchestrator/sessions/' . $sessionId . '/archive');
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	private function listSessions(array $filters): array
	{
		$response = $this->client->get('api/orchestrator/sessions', ['query' => $filters]);

		/** @var array{data?: array<int, array<string, mixed>>} $decoded */
		$decoded = Json::decode((string) $response->getBody(), Json::FORCE_ARRAY);

		return $decoded['data'] ?? [];
	}
}
