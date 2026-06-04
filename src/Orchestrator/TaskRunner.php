<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Symfony\Component\Console\Output\OutputInterface;

final class TaskRunner
{
	public function __construct(
		private readonly MonitorClient $monitor,
		private readonly WorktreeManager $worktrees,
		private readonly TmuxClaudeDriver $tmux,
		private readonly TurnCoordinator $coordinator,
		private readonly TurnStateStore $turnStates,
		private readonly ContextBundles $bundles,
		private readonly PolicySettingsWriter $policyWriter,
		private readonly RepoSynchronizer $repoSync,
		private readonly ?OrchestratorRunReporter $reporter = null,
	) {
	}

	/**
	 * @param array<string, mixed> $task
	 * @param array<int, array<string, mixed>> $contextSources
	 * @param array<string, mixed> $pollMeta
	 */
	public function run(array $task, array $contextSources, array $pollMeta, OutputInterface $output): void
	{
		$taskId = (int) ($task['id'] ?? 0);

		if ($taskId <= 0) {
			throw new \RuntimeException('Task is missing numeric id.');
		}

		$repoPath = (string) ($pollMeta['orchestrator_repo_path'] ?? '');

		if ($repoPath === '') {
			throw new \RuntimeException('orchestrator_repo_path is not configured on the project.');
		}

		$defaultBranch = (string) ($pollMeta['orchestrator_default_branch'] ?? 'prod');
		$settings = \is_array($pollMeta['orchestrator_settings'] ?? null) ? $pollMeta['orchestrator_settings'] : [];
		$useWorktrees = (bool) ($settings['use_worktrees'] ?? false);

		if ($useWorktrees) {
			// Worktrees branch off origin/* — refresh remote refs so the new branch is current.
			$this->repoSync->fetch($repoPath);
			$worktreePath = $this->worktrees->ensureWorktree($repoPath, $defaultBranch, $taskId);
			$branchName = 'autonomy/task-' . $taskId;
		} else {
			$worktreePath = \rtrim($repoPath, '/');

			if ($this->turnStates->read($worktreePath) !== null) {
				$output->writeln(\sprintf(
					'<comment>Task #%d: repo %s is busy with an open turn — returning task to queue.</comment>',
					$taskId,
					$worktreePath,
				));
				$this->monitor->patchTask($taskId, ['status' => 'pending']);

				return;
			}

			// The orchestrator owns git: never start an agent on a dirty tree or stale code.
			if (!$this->repoSync->isClean($worktreePath)) {
				$output->writeln(\sprintf(
					'<comment>Task #%d: repo %s has uncommitted changes (outside .orchestrator/) — returning task to queue.</comment>',
					$taskId,
					$worktreePath,
				));
				$this->monitor->patchTask($taskId, ['status' => 'pending']);

				return;
			}

			if (!$this->repoSync->pullFastForward($worktreePath)) {
				$output->writeln(\sprintf(
					'<comment>Task #%d: git pull --ff-only failed for %s (divergence / no upstream / network) — returning task to queue.</comment>',
					$taskId,
					$worktreePath,
				));
				$this->monitor->patchTask($taskId, ['status' => 'pending']);

				return;
			}

			$branchName = $this->worktrees->currentBranch($worktreePath) ?? $defaultBranch;
		}

		$projectId = (int) ($pollMeta['project_id'] ?? 0);
		$tmuxName = \sprintf('orch-p%d-t%d', $projectId, $taskId);
		$claudeSessionId = Uuid::v4();

		$sessionResponse = $this->monitor->createSession([
			'triage_task_id' => $taskId,
			'claude_session_id' => $claudeSessionId,
			'worktree_path' => $worktreePath,
			'claude_session_cwd' => $worktreePath,
			'branch_name' => $branchName,
			'tmux_session_name' => $tmuxName,
			'state' => 'running',
		]);

		/** @var array<string, mixed> $session */
		$session = $sessionResponse['data'] ?? $sessionResponse;
		$sessionId = (int) ($session['id'] ?? 0);

		if ($sessionId <= 0) {
			throw new \RuntimeException('Failed to create agent session.');
		}

		$this->monitor->createEvent([
			'agent_session_id' => $sessionId,
			'triage_task_id' => $taskId,
			'event_type' => 'session_started',
			'payload' => ['worktree_path' => $worktreePath],
		]);

		$deliveryUuid = Uuid::v4();
		$brief = $this->buildBrief($task, $deliveryUuid, $useWorktrees, $worktreePath, $branchName);
		$messageResponse = $this->monitor->createMessage([
			'agent_session_id' => $sessionId,
			'delivery_uuid' => $deliveryUuid,
			'phase' => 'assess',
			'body' => $brief,
		]);

		/** @var array<string, mixed> $messageData */
		$messageData = $messageResponse['data'] ?? $messageResponse;
		$messageId = (int) ($messageData['id'] ?? 0);

		if ($this->reporter !== null) {
			$this->reporter->taskStarting($task, $tmuxName, $worktreePath);
		} else {
			$output->writeln(\sprintf('<info>Task #%d: starting tmux session %s</info>', $taskId, $tmuxName));
		}

		$merged = $this->bundles->merge($contextSources);

		$this->coordinator->prepareForTurn($worktreePath);
		$settingsPath = $this->policyWriter->write($worktreePath, $settings, $merged, !$useWorktrees);
		$this->tmux->startNew(
			$tmuxName,
			$worktreePath,
			$claudeSessionId,
			$merged['env'],
			$merged['add_dirs'],
			$settingsPath,
		);
		\sleep(8);

		$rename = \sprintf(
			'/rename %s — %s',
			(string) ($task['ticket_number'] ?? '#' . $taskId),
			(string) ($task['external_title'] ?? 'task'),
		);
		$this->tmux->sendKeys($tmuxName, $rename);
		\sleep(1);
		$output->writeln('<comment>Submitting brief to Claude (paste + Enter)…</comment>');
		$this->tmux->sendKeys($tmuxName, $brief);

		$this->monitor->patchMessage($messageId, [
			'delivery_status' => 'delivered',
			'delivered_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
		]);

		$this->turnStates->write($worktreePath, [
			'task_id' => $taskId,
			'session_id' => $sessionId,
			'turn_number' => 1,
			'phase' => 'assess',
			'message_id' => $messageId,
			'submitted_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
			'pane_sha1' => \sha1($this->tmux->capturePane($tmuxName)),
			'nudges' => 0,
		]);

		$output->writeln('<comment>Brief submitted — milestone will be collected by subsequent runs.</comment>');
	}

	/**
	 * Thin bootstrap brief. The agent learns what it can do from the capabilities
	 * manifest it pulls itself (pull model) — we no longer splice per-source skill_md.
	 * @param array<string, mixed> $task
	 */
	private function buildBrief(
		array $task,
		string $deliveryUuid,
		bool $useWorktrees,
		string $workPath,
		string $branchName,
	): string {
		$ticket = (string) ($task['ticket_number'] ?? '#' . ($task['id'] ?? '?'));
		$title = (string) ($task['external_title'] ?? '');
		$url = (string) ($task['external_url'] ?? '');
		$source = (string) ($task['source'] ?? 'unknown');
		$milestonePath = TurnCoordinator::MILESTONE_RELATIVE_PATH;

		$workContext = $useWorktrees
			? "You are an autonomous programming agent working in a dedicated git worktree on branch {$branchName}."
			: "You are an autonomous programming agent working directly in the project repository at {$workPath} "
				. "on branch {$branchName}. The repository may contain uncommitted local changes of a human developer — "
				. 'do NOT switch branches, do NOT commit, stash, or revert anything unless the task explicitly requires a code change.';

		$body = <<<MARKDOWN
{$workContext}

You are running autonomously under the Liquid Monitor orchestrator. Before anything else, fetch your
operating manifest and follow it:

    curl -s "\$MONITOR_API_URL/api/orchestrator/capabilities" -H "X-Api-Key: \$MONITOR_TRIAGE_API_KEY"

The manifest declares your mode, the rules you must obey, and every reference resource available to you
(production errors, logs, database, cron job-runs, task detail, past results) together with the exact
method and URL to call. Pull ALL reference data through the monitor API only — never read production
logs, databases or external task systems (Freelo, …) directly. You edit code locally in this working
copy; the orchestrator owns every git operation. Authenticate each monitor call with the header
`X-Api-Key: \$MONITOR_TRIAGE_API_KEY` against the base URL `\$MONITOR_API_URL`.

Task {$ticket} from "{$source}":
Title: {$title}
URL: {$url}

Phases in this turn: assess the assignment, then either answer, implement a code change, or hand off.

When finished, write your milestone to the file `{$milestonePath}` in the worktree root
(create the directory if needed). The file must contain a single RAW JSON object — no markdown
fences, no surrounding prose. Never commit this file. After writing it, stop and wait.

The JSON object must have these keys:
  - phase: "assess" | "work" | "review_prep"
  - category: "answer" | "needs_work" | "implemented" | "handoff" | "unclear" | "error"
  - confidence: "low" | "medium" | "high"
  - draft_response_md: markdown for human review
  - reasoning_md: optional
  - requires_test: boolean (true if you changed code and tests should run)
  - context_sources: string[]
  - tools_called: array of {tool,count}
  - input_tokens, output_tokens, estimated_cost_usd: best-effort numbers
MARKDOWN;

		return "[Orchestrator | phase=assess | msg={$deliveryUuid}]\n\n" . $body;
	}
}
