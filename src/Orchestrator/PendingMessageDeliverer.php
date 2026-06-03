<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Symfony\Component\Console\Output\OutputInterface;

final class PendingMessageDeliverer
{
	public function __construct(
		private readonly MonitorClient $monitor,
		private readonly TmuxClaudeDriver $tmux,
		private readonly TurnCoordinator $coordinator,
		private readonly TurnStateStore $turnStates,
		private readonly ContextBundles $bundles,
		private readonly PolicySettingsWriter $policyWriter,
	) {
	}

	/**
	 * Deliver at most one pending message per session and return immediately —
	 * the resulting turn is collected by TurnCollector in subsequent runs.
	 * @param array<int, array<string, mixed>> $sessions
	 * @param array<string, mixed> $pollMeta
	 */
	public function deliverAll(array $sessions, array $pollMeta, OutputInterface $output): int
	{
		/** @var array<int, array<string, mixed>> $contextSources */
		$contextSources = \is_array($pollMeta['context_sources'] ?? null) ? $pollMeta['context_sources'] : [];
		$merged = $this->bundles->merge($contextSources);
		$settings = \is_array($pollMeta['orchestrator_settings'] ?? null) ? $pollMeta['orchestrator_settings'] : [];
		$delivered = 0;

		foreach ($sessions as $session) {
			$sessionId = (int) ($session['id'] ?? 0);
			$tmuxName = (string) ($session['tmux_session_name'] ?? '');
			$taskId = (int) ($session['triage_task_id'] ?? 0);
			$cwd = (string) ($session['claude_session_cwd'] ?? $session['worktree_path'] ?? '');

			if ($sessionId <= 0 || $tmuxName === '' || $taskId <= 0) {
				continue;
			}

			if ($cwd !== '' && $this->turnStates->read($cwd) !== null) {
				$output->writeln(\sprintf(
					'<comment>Session #%d has an open turn — leaving queued message(s) for later.</comment>',
					$sessionId,
				));

				continue;
			}

			$messages = $this->monitor->listMessages($sessionId, 'pending_delivery');

			if ($messages === []) {
				continue;
			}

			if (!$this->deliverOne($session, $messages[0], $merged, $settings, $output)) {
				continue;
			}

			$delivered++;
		}

		return $delivered;
	}

	/**
	 * @param array<string, mixed> $session
	 * @param array<string, mixed> $message
	 * @param array{env: array<string, string>, add_dirs: array<int, string>, allowed_tools: array<int, string>} $merged
	 * @param array<string, mixed> $settings
	 */
	private function deliverOne(array $session, array $message, array $merged, array $settings, OutputInterface $output): bool
	{
		$messageId = (int) ($message['id'] ?? 0);
		$tmuxName = (string) ($session['tmux_session_name'] ?? '');
		$body = (string) ($message['body'] ?? '');
		$cwd = (string) ($session['claude_session_cwd'] ?? $session['worktree_path'] ?? '');
		$claudeSessionId = (string) ($session['claude_session_id'] ?? '');
		$sessionId = (int) ($session['id'] ?? 0);
		$taskId = (int) ($session['triage_task_id'] ?? 0);

		if ($messageId <= 0 || $body === '' || $tmuxName === '' || $taskId <= 0) {
			return false;
		}

		if ($cwd === '') {
			$this->monitor->patchMessage($messageId, [
				'delivery_status' => 'failed',
				'failure_reason' => 'session has no worktree path — cannot track the turn',
			]);

			return false;
		}

		$output->writeln(\sprintf(
			'<info>Delivering queued message #%d to session #%d (task #%d, tmux %s)…</info>',
			$messageId,
			$sessionId,
			$taskId,
			$tmuxName,
		));

		$this->monitor->patchMessage($messageId, ['delivery_status' => 'delivering']);

		if (!$this->tmux->sessionExists($tmuxName)) {
			if ($claudeSessionId !== '') {
				// Settings are a per-launch layer — regenerate them for resume too.
				$useWorktrees = (bool) ($settings['use_worktrees'] ?? false);
				$settingsPath = $this->policyWriter->write($cwd, $settings, $merged, !$useWorktrees);
				$this->tmux->resume(
					$tmuxName,
					$cwd,
					$claudeSessionId,
					$merged['env'],
					$merged['add_dirs'],
					$settingsPath,
				);
				$this->monitor->patchSession($sessionId, [
					'state' => 'running',
					'suspended_at' => null,
					'last_activity_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
				]);
				\sleep(5);
			}
		}

		if (!$this->tmux->sessionExists($tmuxName)) {
			$this->monitor->patchMessage($messageId, [
				'delivery_status' => 'failed',
				'failure_reason' => 'tmux session not available',
			]);

			return false;
		}

		$turnNumber = $this->monitor->nextTurnNumber($sessionId);

		$output->writeln('<comment>Submitting message to Claude (paste + Enter)…</comment>');
		$this->coordinator->prepareForTurn($cwd);

		$body .= \sprintf(
			"\n\nWhen finished with this turn, write your milestone as a raw JSON object (no markdown fences) to %s in the worktree root, then stop and wait.",
			TurnCoordinator::MILESTONE_RELATIVE_PATH,
		);
		$this->tmux->sendKeys($tmuxName, $body);

		$this->monitor->patchMessage($messageId, [
			'delivery_status' => 'delivered',
			'delivered_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
		]);

		$this->turnStates->write($cwd, [
			'task_id' => $taskId,
			'session_id' => $sessionId,
			'turn_number' => $turnNumber,
			'phase' => (string) ($message['phase'] ?? 'clarify'),
			'message_id' => $messageId,
			'submitted_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
			'pane_sha1' => \sha1($this->tmux->capturePane($tmuxName)),
			'nudges' => 0,
		]);

		$output->writeln('<comment>Message submitted — milestone will be collected by subsequent runs.</comment>');

		return true;
	}
}
