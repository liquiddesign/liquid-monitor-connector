<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Symfony\Component\Console\Output\OutputInterface;

final class WorktreeCleanup
{
	public function __construct(
		private readonly MonitorClient $monitor,
		private readonly WorktreeManager $worktrees,
		private readonly string $repoPath,
	) {
	}

	/**
	 * @param array<int, array<string, mixed>> $sessions
	 */
	public function cleanupArchived(array $sessions, OutputInterface $output): void
	{
		foreach ($sessions as $session) {
			$sessionId = (int) ($session['id'] ?? 0);
			$taskId = (int) ($session['triage_task_id'] ?? 0);
			$worktreePath = (string) ($session['worktree_path'] ?? '');

			if ($sessionId <= 0 || $taskId <= 0 || $worktreePath === '') {
				continue;
			}

			try {
				$this->worktrees->removeWorktree($this->repoPath, $taskId);
				$this->monitor->patchSession($sessionId, [
					'worktree_path' => null,
					'claude_session_cwd' => null,
					'worktree_removed_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
				]);
				$output->writeln(\sprintf('<info>Removed worktree for task #%d</info>', $taskId));
			} catch (\Throwable $e) {
				$output->writeln(\sprintf(
					'<error>Worktree cleanup for task #%d failed: %s</error>',
					$taskId,
					$e->getMessage(),
				));
			}
		}
	}
}
