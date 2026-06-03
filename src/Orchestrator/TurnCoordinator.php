<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class TurnCoordinator
{
	/** Where the agent writes its milestone, relative to the worktree root. */
	public const string MILESTONE_RELATIVE_PATH = '.orchestrator/milestone.json';

	public function __construct(
		private readonly MonitorClient $monitor,
		private readonly TmuxClaudeDriver $tmux,
		private readonly PathGuard $pathGuard,
		private readonly TurnStateStore $turnStates,
		private readonly Outbox $outbox,
	) {
	}

	/**
	 * Remove stale milestone + turn-state files before submitting a prompt, so a
	 * later collect pass cannot pick up the previous turn's result (worktrees are reused).
	 */
	public function prepareForTurn(string $worktreePath): void
	{
		$this->turnStates->clear($worktreePath);
		$file = $this->milestoneFilePath($worktreePath);

		if ($file === null || !\is_file($file)) {
			return;
		}

		FileSystem::delete($file);
	}

	public function milestoneFilePath(string $worktreePath): ?string
	{
		$worktreePath = \rtrim($worktreePath, '/');

		return $worktreePath === '' ? null : $worktreePath . '/' . self::MILESTONE_RELATIVE_PATH;
	}

	/**
	 * Finalize a turn without losing data on a monitor outage: all local work
	 * (path guard, tests, diff stat) happens first, then the complete set of API
	 * payloads is persisted to the outbox BEFORE any API call. From that point the
	 * turn is owned by the outbox — tmux is killed, milestone/turn-state cleared,
	 * and the API calls are replayed (idempotently) until they all succeed.
	 * @param array<string, mixed> $session
	 * @param array<string, mixed> $task
	 * @param array<string, mixed> $pollMeta
	 * @param array<string, mixed> $milestone
	 */
	public function finalizeTurn(
		array $session,
		array $task,
		array $pollMeta,
		array $milestone,
		int $turnNumber,
		OutputInterface $output,
	): void {
		$sessionId = (int) ($session['id'] ?? 0);
		$taskId = (int) ($task['id'] ?? 0);
		$worktreePath = (string) ($session['worktree_path'] ?? $session['claude_session_cwd'] ?? '');
		$tmuxName = (string) ($session['tmux_session_name'] ?? '');
		$settings = \is_array($pollMeta['orchestrator_settings'] ?? null) ? $pollMeta['orchestrator_settings'] : [];

		$category = (string) ($milestone['category'] ?? 'needs_work');

		if ($category === 'implemented' && $this->pathGuard->touchesRestrictedPaths($worktreePath, $settings)) {
			$category = 'handoff';
			$milestone['category'] = 'handoff';
			$milestone['draft_response_md'] = ($milestone['draft_response_md'] ?? '') . "\n\n_(Orchestrator: changed restricted paths — handed off for human review.)_";
		}

		$turnCategory = $category;
		$verified = true;

		if ($category === 'implemented' || ($milestone['requires_test'] ?? false) === true) {
			$verified = $this->runComposerTest($worktreePath, $output);
		}

		if (!$verified && $category === 'implemented') {
			$category = 'handoff';
		}

		$gitDiffStat = $this->gitDiffStat($worktreePath);
		$now = (new \DateTimeImmutable())->format(\DATE_ATOM);

		$entry = [
			'task_id' => $taskId,
			'session_id' => $sessionId,
			'create_turn' => [
				'agent_session_id' => $sessionId,
				'triage_task_id' => $taskId,
				'idempotency_key' => Uuid::v4(),
				'turn_number' => $turnNumber,
				'phase' => (string) ($milestone['phase'] ?? 'work'),
				'category' => $turnCategory,
				'raw_output_json' => $milestone,
				'orchestrator_verified' => $verified,
				'completed_at' => $now,
			],
			'post_result' => [
				'idempotency_key' => Uuid::v4(),
				'category' => $category,
				'draft_response_md' => (string) ($milestone['draft_response_md'] ?? ''),
				'reasoning_md' => (string) ($milestone['reasoning_md'] ?? ''),
				'confidence' => (string) ($milestone['confidence'] ?? 'medium'),
				'context_sources' => $milestone['context_sources'] ?? [],
				'tools_called' => $milestone['tools_called'] ?? [],
				'input_tokens' => isset($milestone['input_tokens']) ? (int) $milestone['input_tokens'] : null,
				'output_tokens' => isset($milestone['output_tokens']) ? (int) $milestone['output_tokens'] : null,
				'estimated_cost_usd' => isset($milestone['estimated_cost_usd']) ? (float) $milestone['estimated_cost_usd'] : null,
				'provider' => 'anthropic',
				'orchestrator_metadata' => [
					'git_diff_stat' => $gitDiffStat,
					'turn_number' => $turnNumber,
					'verified' => $verified,
				],
			],
			'patch_task' => [
				'status' => 'completed',
				'finished_at' => $now,
			],
			'create_event' => [
				'agent_session_id' => $sessionId,
				'triage_task_id' => $taskId,
				'event_type' => 'turn_completed',
				'payload' => ['category' => $category, 'verified' => $verified],
			],
			'patch_session' => [
				'state' => 'suspended',
				'suspended_at' => $now,
				'last_activity_at' => $now,
			],
		];

		$outboxRoot = $this->outboxRoot($pollMeta, $worktreePath);
		$key = \sprintf('task%d-turn%d-%s', $taskId, $turnNumber, Uuid::v4());
		$this->outbox->enqueue($outboxRoot, $key, $entry);

		// The turn is now owned by the outbox — release the tmux pane and the repo mutex.
		if ($tmuxName !== '') {
			$this->tmux->kill($tmuxName);
		}

		if ($worktreePath !== '') {
			$this->prepareForTurn($worktreePath);
		}

		$this->outbox->flush($outboxRoot, $this->monitor, $output);

		$output->writeln(\sprintf('<info>Task #%d: result posted (%s), session suspended.</info>', $taskId, $category));
	}

	/**
	 * @param array<string, mixed> $pollMeta
	 */
	private function outboxRoot(array $pollMeta, string $worktreePath): string
	{
		$repoPath = \rtrim((string) ($pollMeta['orchestrator_repo_path'] ?? ''), '/');
		$base = $repoPath !== '' ? $repoPath : \rtrim($worktreePath, '/');

		return $base . '/' . Outbox::OUTBOX_RELATIVE_PATH;
	}

	private function runComposerTest(string $worktreePath, OutputInterface $output): bool
	{
		if ($worktreePath === '' || !\is_dir($worktreePath)) {
			return false;
		}

		$output->writeln('<info>Running composer test in worktree…</info>');

		$process = new Process(['composer', 'test'], $worktreePath);
		$process->setTimeout(600);
		$process->run();

		if (!$process->isSuccessful()) {
			$output->writeln('<error>composer test failed.</error>');

			return false;
		}

		$output->writeln('<info>composer test passed.</info>');

		return true;
	}

	private function gitDiffStat(string $worktreePath): ?string
	{
		if ($worktreePath === '' || !\is_dir($worktreePath)) {
			return null;
		}

		$process = new Process(['git', '-C', $worktreePath, 'diff', '--stat'], null, null, null, 30);
		$process->run();

		if (!$process->isSuccessful()) {
			return null;
		}

		$output = Strings::trim($process->getOutput());

		return $output === '' ? '(no uncommitted changes)' : $output;
	}
}
