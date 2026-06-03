<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Non-blocking counterpart to a submitted turn: each orchestrator run inspects
 * every running session with an open turn (turn-state file present) and either
 * finalizes it (milestone written), nudges an idle agent, or fails the turn on
 * timeout / dead tmux. Nothing here waits — progress happens across cron runs.
 */
final class TurnCollector
{
	public function __construct(
		private readonly MonitorClient $monitor,
		private readonly TmuxClaudeDriver $tmux,
		private readonly JsonMilestoneParser $parser,
		private readonly TurnCoordinator $coordinator,
		private readonly TurnStateStore $turnStates,
		private readonly int $turnTimeoutSeconds = 900,
		private readonly int $maxNudges = 2,
	) {
	}

	/**
	 * @param array<int, array<string, mixed>> $sessions
	 * @param array<string, mixed> $pollMeta
	 * @return int Number of turns finalized (successfully or as failure).
	 */
	public function collectAll(array $sessions, array $pollMeta, OutputInterface $output): int
	{
		$finalized = 0;

		foreach ($sessions as $session) {
			if (($session['state'] ?? '') !== 'running') {
				continue;
			}

			$worktree = (string) ($session['worktree_path'] ?? $session['claude_session_cwd'] ?? '');

			if ($worktree === '' || !\is_dir($worktree)) {
				continue;
			}

			$state = $this->turnStates->read($worktree);

			if ($state === null) {
				continue;
			}

			// In repo mode (use_worktrees=false) sessions share the same path — the
			// open turn belongs to exactly one of them.
			if ((int) ($state['session_id'] ?? 0) !== (int) ($session['id'] ?? 0)) {
				continue;
			}

			if (!$this->collectOne($session, $state, $worktree, $pollMeta, $output)) {
				continue;
			}

			$finalized++;
		}

		return $finalized;
	}

	/**
	 * @param array<string, mixed> $session
	 * @param array<string, mixed> $state
	 * @param array<string, mixed> $pollMeta
	 */
	private function collectOne(
		array $session,
		array $state,
		string $worktree,
		array $pollMeta,
		OutputInterface $output,
	): bool {
		$taskId = (int) ($session['triage_task_id'] ?? $state['task_id'] ?? 0);
		$turnNumber = (int) ($state['turn_number'] ?? 1);
		$task = ['id' => $taskId];
		$tmuxName = (string) ($session['tmux_session_name'] ?? '');

		$milestoneFile = $this->coordinator->milestoneFilePath($worktree);
		$milestone = $milestoneFile !== null ? $this->parser->extractFromFile($milestoneFile) : null;

		if ($milestone !== null) {
			$output->writeln(\sprintf('<info>Task #%d: milestone found — finalizing turn %d.</info>', $taskId, $turnNumber));
			$this->coordinator->finalizeTurn($session, $task, $pollMeta, $milestone, $turnNumber, $output);

			return true;
		}

		if ($tmuxName === '' || !$this->tmux->sessionExists($tmuxName)) {
			$this->finalizeFailed(
				$session,
				$task,
				$pollMeta,
				$state,
				$turnNumber,
				'Claude tmux session disappeared mid-turn without writing a milestone.',
				$output,
			);

			return true;
		}

		$submittedAt = $this->parseTimestamp((string) ($state['submitted_at'] ?? ''));

		if ($submittedAt !== null && \time() - $submittedAt >= $this->turnTimeoutSeconds) {
			$this->finalizeFailed(
				$session,
				$task,
				$pollMeta,
				$state,
				$turnNumber,
				\sprintf('Turn timed out after %d seconds without a milestone.', $this->turnTimeoutSeconds),
				$output,
			);

			return true;
		}

		$paneSha = \sha1($this->tmux->capturePane($tmuxName));

		if ($paneSha !== (string) ($state['pane_sha1'] ?? '')) {
			$state['pane_sha1'] = $paneSha;
			$this->turnStates->write($worktree, $state);
			$this->monitor->patchSession((int) ($session['id'] ?? 0), [
				'last_activity_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
			]);

			return false;
		}

		$nudges = (int) ($state['nudges'] ?? 0);

		if ($nudges < $this->maxNudges) {
			$output->writeln(\sprintf(
				'<comment>Task #%d: pane idle without milestone — nudging agent (%d/%d).</comment>',
				$taskId,
				$nudges + 1,
				$this->maxNudges,
			));
			$this->tmux->sendKeys($tmuxName, \sprintf(
				'Write your milestone now: save it as a raw JSON object (no markdown fences) to %s in the worktree root, as specified in the brief.',
				TurnCoordinator::MILESTONE_RELATIVE_PATH,
			));
			$state['nudges'] = $nudges + 1;
			$this->turnStates->write($worktree, $state);
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $session
	 * @param array<string, mixed> $task
	 * @param array<string, mixed> $pollMeta
	 * @param array<string, mixed> $state
	 */
	private function finalizeFailed(
		array $session,
		array $task,
		array $pollMeta,
		array $state,
		int $turnNumber,
		string $reason,
		OutputInterface $output,
	): void {
		$output->writeln(\sprintf('<error>Task #%d: %s</error>', (int) ($task['id'] ?? 0), $reason));

		$milestone = [
			'phase' => (string) ($state['phase'] ?? 'work'),
			'category' => 'error',
			'confidence' => 'low',
			'draft_response_md' => '_(Orchestrator: ' . $reason . ')_',
			'reasoning_md' => '',
			'requires_test' => false,
			'context_sources' => [],
			'tools_called' => [],
		];

		$this->coordinator->finalizeTurn($session, $task, $pollMeta, $milestone, $turnNumber, $output);
	}

	private function parseTimestamp(string $value): ?int
	{
		if ($value === '') {
			return null;
		}

		try {
			return (new \DateTimeImmutable($value))->getTimestamp();
		} catch (\Exception) {
			return null;
		}
	}
}
