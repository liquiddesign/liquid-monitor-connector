<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;

/**
 * Generates the per-launch Claude Code settings file (permissions + hooks) and the
 * policy file consumed by bin/orchestrator-guard. Written before every Claude
 * start/resume so the agent's restrictions are enforced deterministically, not
 * just stated in the brief.
 */
final class PolicySettingsWriter
{
	/** Where the generated Claude settings file lives, relative to the worktree root. */
	public const string SETTINGS_RELATIVE_PATH = '.orchestrator/claude-settings.json';

	/** Where the guard policy lives, relative to the worktree root. */
	public const string POLICY_RELATIVE_PATH = '.orchestrator/policy.json';

	/** Git subcommands denied in repo mode when the project does not override them. */
	public const array DEFAULT_DENY_GIT_OPERATIONS = ['commit', 'checkout', 'switch', 'reset', 'stash'];

	/** Control files the agent must never edit (milestone.json stays writable). */
	private const array PROTECTED_RELATIVE_PATHS = [
		'.orchestrator/turn-state.json',
		'.orchestrator/policy.json',
		'.orchestrator/claude-settings.json',
		'.orchestrator/outbox/**',
	];

	/**
	 * Write claude-settings.json + policy.json for the given cwd and return the settings path.
	 * @param array<string, mixed> $settings orchestrator_settings from the poll response
	 * @param array{env: array<string, string>, add_dirs: array<int, string>, allowed_tools: array<int, string>} $merged
	 * @param bool $repoMode True when the agent works directly in the main repo (use_worktrees=false)
	 */
	public function write(string $cwd, array $settings, array $merged, bool $repoMode): string
	{
		$cwd = \rtrim($cwd, '/');

		if ($cwd === '') {
			throw new \RuntimeException('Cannot write policy settings: cwd is empty.');
		}

		$denyGit = $this->denyGitOperations($settings);
		$guardCommand = 'php ' . \escapeshellarg(\dirname(__DIR__, 2) . '/bin/orchestrator-guard');

		$deny = ['Bash(git push:*)', 'Bash(rm -rf:*)'];

		if ($repoMode) {
			foreach ($denyGit as $operation) {
				$deny[] = \sprintf('Bash(git %s:*)', $operation);
			}
		}

		foreach (self::PROTECTED_RELATIVE_PATHS as $path) {
			$deny[] = \sprintf('Edit(%s)', $path);
			$deny[] = \sprintf('Write(%s)', $path);
		}

		$claudeSettings = [
			'permissions' => [
				'deny' => $deny,
				'allow' => \array_values($merged['allowed_tools']),
			],
			'hooks' => [
				'PreToolUse' => [
					[
						'matcher' => 'Bash|Edit|Write',
						'hooks' => [
							['type' => 'command', 'command' => $guardCommand],
						],
					],
				],
				'Stop' => [
					[
						'hooks' => [
							['type' => 'command', 'command' => $guardCommand],
						],
					],
				],
			],
		];

		$policy = [
			'repo_mode' => $repoMode,
			'deny_git' => $denyGit,
		];

		$settingsPath = $cwd . '/' . self::SETTINGS_RELATIVE_PATH;
		FileSystem::write($settingsPath, Json::encode($claudeSettings, Json::PRETTY));
		FileSystem::write($cwd . '/' . self::POLICY_RELATIVE_PATH, Json::encode($policy, Json::PRETTY));

		return $settingsPath;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<int, string>
	 */
	private function denyGitOperations(array $settings): array
	{
		$raw = $settings['deny_git_operations'] ?? null;

		if (!\is_array($raw)) {
			return self::DEFAULT_DENY_GIT_OPERATIONS;
		}

		$operations = [];

		foreach ($raw as $operation) {
			if (\is_string($operation) && \preg_match('/^[a-z-]+$/', $operation) === 1) {
				$operations[] = $operation;
			}
		}

		return $operations === [] ? self::DEFAULT_DENY_GIT_OPERATIONS : $operations;
	}
}
