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
	) {
	}

	/**
	 * @param array<int, array<string, mixed>> $sessions
	 * @param array<string, mixed> $pollMeta
	 */
	public function deliverAll(array $sessions, array $pollMeta, OutputInterface $output): void
	{
		foreach ($sessions as $session) {
			$sessionId = (int) ($session['id'] ?? 0);
			$tmuxName = (string) ($session['tmux_session_name'] ?? '');
			$taskId = (int) ($session['triage_task_id'] ?? 0);

			if ($sessionId <= 0 || $tmuxName === '' || $taskId <= 0) {
				continue;
			}

			$messages = $this->monitor->listMessages($sessionId, 'pending_delivery');
			$turnNumber = $this->monitor->nextTurnNumber($sessionId);

			foreach ($messages as $message) {
				$this->deliverOne($session, $message, $pollMeta, $turnNumber, $output);
				$turnNumber++;
			}
		}
	}

	/**
	 * @param array<string, mixed> $session
	 * @param array<string, mixed> $message
	 * @param array<string, mixed> $pollMeta
	 */
	private function deliverOne(
		array $session,
		array $message,
		array $pollMeta,
		int $turnNumber,
		OutputInterface $output,
	): void {
		$messageId = (int) ($message['id'] ?? 0);
		$tmuxName = (string) ($session['tmux_session_name'] ?? '');
		$body = (string) ($message['body'] ?? '');
		$cwd = (string) ($session['claude_session_cwd'] ?? $session['worktree_path'] ?? '');
		$claudeSessionId = (string) ($session['claude_session_id'] ?? '');
		$taskId = (int) ($session['triage_task_id'] ?? 0);

		if ($messageId <= 0 || $body === '' || $tmuxName === '' || $taskId <= 0) {
			return;
		}

		$this->monitor->patchMessage($messageId, ['delivery_status' => 'delivering']);

		if (!$this->tmux->sessionExists($tmuxName)) {
			if ($cwd !== '' && $claudeSessionId !== '') {
				$this->tmux->resume($tmuxName, $cwd, $claudeSessionId);
				$this->monitor->patchSession((int) $session['id'], [
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

			return;
		}

		$this->tmux->sendKeys($tmuxName, $body);

		$milestone = $this->coordinator->waitForMilestone($tmuxName, $output);

		$this->monitor->patchMessage($messageId, [
			'delivery_status' => 'delivered',
			'delivered_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
		]);

		/** @var array<string, mixed> $task */
		$task = ['id' => $taskId, 'ticket_number' => $session['triage_task']['ticket_number'] ?? null];

		$this->coordinator->finalizeTurn($session, $task, $pollMeta, $milestone, $turnNumber, $output);
	}
}
