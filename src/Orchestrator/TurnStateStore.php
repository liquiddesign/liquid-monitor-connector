<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Nette\Utils\JsonException;

/**
 * Persists the state of an open (submitted, not yet finalized) turn between
 * orchestrator runs, so the worker can stay non-blocking: one run submits the
 * prompt, later runs collect the milestone. Stored next to the milestone file.
 */
final class TurnStateStore
{
	/** Where the open-turn state lives, relative to the worktree root. */
	public const string TURN_STATE_RELATIVE_PATH = '.orchestrator/turn-state.json';

	/**
	 * @return array<string, mixed>|null Null when no turn is open for the worktree.
	 */
	public function read(string $worktreePath): ?array
	{
		$file = $this->path($worktreePath);

		if ($file === null || !\is_file($file)) {
			return null;
		}

		try {
			$decoded = Json::decode((string) \file_get_contents($file), Json::FORCE_ARRAY);
		} catch (JsonException) {
			return null;
		}

		if (!\is_array($decoded)) {
			return null;
		}

		/** @var array<string, mixed> $state */
		$state = $decoded;

		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public function write(string $worktreePath, array $state): void
	{
		$file = $this->path($worktreePath);

		if ($file === null) {
			throw new \RuntimeException('Cannot persist turn state: worktree path is empty.');
		}

		FileSystem::write($file, Json::encode($state, Json::PRETTY));
	}

	public function clear(string $worktreePath): void
	{
		$file = $this->path($worktreePath);

		if ($file === null || !\is_file($file)) {
			return;
		}

		FileSystem::delete($file);
	}

	private function path(string $worktreePath): ?string
	{
		$worktreePath = \rtrim($worktreePath, '/');

		return $worktreePath === '' ? null : $worktreePath . '/' . self::TURN_STATE_RELATIVE_PATH;
	}
}
