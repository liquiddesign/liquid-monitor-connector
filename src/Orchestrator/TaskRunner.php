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

			$branchName = $this->worktrees->currentBranch($worktreePath) ?? $defaultBranch;
		}

		$tmuxName = 'orch-' . $taskId;
		$claudeSessionId = $this->uuid4();

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

		$deliveryUuid = $this->uuid4();
		$brief = $this->buildBrief($task, $contextSources, $deliveryUuid, $useWorktrees, $worktreePath, $branchName);
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
		$this->tmux->startNew(
			$tmuxName,
			$worktreePath,
			$claudeSessionId,
			$merged['env'],
			$merged['add_dirs'],
			$merged['allowed_tools'],
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
	 * @param array<string, mixed> $task
	 * @param array<int, array<string, mixed>> $contextSources
	 */
	private function buildBrief(
		array $task,
		array $contextSources,
		string $deliveryUuid,
		bool $useWorktrees,
		string $workPath,
		string $branchName,
	): string {
		$skillBlocks = [];

		foreach ($contextSources as $source) {
			if (\is_string($source['skill_md'] ?? null) && $source['skill_md'] !== '') {
				$type = (string) ($source['type'] ?? 'context');
				$skillBlocks[] = "## skill: {$type}\n\n" . $source['skill_md'];
			}
		}

		$skills = $skillBlocks === [] ? '' : "\n\n# Skills\n\n" . \implode("\n\n", $skillBlocks) . "\n";
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

Task {$ticket} from "{$source}":
Title: {$title}
URL: {$url}
{$skills}
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

	private function uuid4(): string
	{
		$bytes = \random_bytes(16);
		$bytes[6] = \chr(\ord($bytes[6]) & 0x0f | 0x40);
		$bytes[8] = \chr(\ord($bytes[8]) & 0x3f | 0x80);

		return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($bytes), 4));
	}
}
