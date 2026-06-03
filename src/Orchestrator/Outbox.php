<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Durable buffer for turn-finalization API calls. The coordinator writes the
 * complete set of payloads BEFORE making any API call; a crash or monitor outage
 * mid-finalization leaves the entry on disk and a later run replays the missing
 * calls (idempotency keys make the replay safe). Lives in the MAIN repo's
 * .orchestrator/outbox — never in a worktree, which may be removed.
 */
final class Outbox
{
	/** Where the outbox lives, relative to the MAIN repo root. */
	public const string OUTBOX_RELATIVE_PATH = '.orchestrator/outbox';

	/** Order in which the finalization calls are replayed. */
	public const array STEPS = ['create_turn', 'post_result', 'patch_task', 'create_event', 'patch_session'];

	/**
	 * @param array<string, mixed> $entry
	 */
	public function enqueue(string $outboxRoot, string $key, array $entry): string
	{
		$outboxRoot = \rtrim($outboxRoot, '/');

		if ($outboxRoot === '') {
			throw new \RuntimeException('Cannot enqueue outbox entry: outbox root is empty.');
		}

		if (!isset($entry['done']) || !\is_array($entry['done'])) {
			$entry['done'] = [];
		}

		$file = $outboxRoot . '/' . $key . '.json';
		FileSystem::write($file, Json::encode($entry, Json::PRETTY));

		return $file;
	}

	/**
	 * @return array<string, array<string, mixed>> Entries keyed by outbox key (filename without .json).
	 */
	public function entries(string $outboxRoot): array
	{
		$outboxRoot = \rtrim($outboxRoot, '/');

		if ($outboxRoot === '' || !\is_dir($outboxRoot)) {
			return [];
		}

		$entries = [];
		$files = \glob($outboxRoot . '/*.json') ?: [];
		\sort($files);

		foreach ($files as $file) {
			try {
				$decoded = Json::decode((string) \file_get_contents($file), Json::FORCE_ARRAY);
			} catch (JsonException) {
				continue;
			}

			if (!\is_array($decoded)) {
				continue;
			}

			/** @var array<string, mixed> $entry */
			$entry = $decoded;
			$entries[\basename($file, '.json')] = $entry;
		}

		return $entries;
	}

	/**
	 * Replay pending entries against the monitor. Completed steps are persisted
	 * after each call, the entry file is removed once every step is done.
	 */
	public function flush(string $outboxRoot, MonitorClient $monitor, OutputInterface $output): int
	{
		return $this->flushWith(
			$outboxRoot,
			function (string $step, array $entry) use ($monitor): void {
				$this->dispatch($monitor, $step, $entry);
			},
			$output,
		);
	}

	/**
	 * Testable core of flush(): $executor performs one named step for an entry
	 * and throws on failure.
	 * @param callable(string, array<string, mixed>): void $executor
	 */
	public function flushWith(string $outboxRoot, callable $executor, OutputInterface $output): int
	{
		$outboxRoot = \rtrim($outboxRoot, '/');
		$flushed = 0;

		foreach ($this->entries($outboxRoot) as $key => $entry) {
			$file = $outboxRoot . '/' . $key . '.json';
			/** @var array<string, bool> $done */
			$done = \is_array($entry['done'] ?? null) ? $entry['done'] : [];
			$failed = false;

			foreach (self::STEPS as $step) {
				if (!isset($entry[$step]) || ($done[$step] ?? false) === true) {
					continue;
				}

				try {
					$executor($step, $entry);
				} catch (\Throwable $e) {
					$output->writeln(\sprintf(
						'<error>Outbox %s: step %s failed (%s) — entry kept for a later run.</error>',
						$key,
						$step,
						$e->getMessage(),
					));
					$failed = true;

					break;
				}

				$done[$step] = true;
				$entry['done'] = $done;
				FileSystem::write($file, Json::encode($entry, Json::PRETTY));
			}

			if ($failed) {
				continue;
			}

			FileSystem::delete($file);
			$flushed++;
			$output->writeln(\sprintf('<info>Outbox %s: flushed.</info>', $key));
		}

		return $flushed;
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private function dispatch(MonitorClient $monitor, string $step, array $entry): void
	{
		$taskId = (int) ($entry['task_id'] ?? 0);
		$sessionId = (int) ($entry['session_id'] ?? 0);

		/** @var array<string, mixed> $payload */
		$payload = \is_array($entry[$step] ?? null) ? $entry[$step] : [];

		match ($step) {
			'create_turn' => $monitor->createTurn($payload),
			'post_result' => $monitor->postResult($taskId, $payload),
			'patch_task' => $monitor->patchTask($taskId, $payload),
			'create_event' => $monitor->createEvent($payload),
			'patch_session' => $monitor->patchSession($sessionId, $payload),
			default => throw new \RuntimeException('Unknown outbox step: ' . $step),
		};
	}
}
