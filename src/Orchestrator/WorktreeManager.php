<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\Strings;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class WorktreeManager
{
	public function ensureWorktree(string $repoPath, string $defaultBranch, int $taskId): string
	{
		$repoPath = \rtrim($repoPath, '/');

		if (!\is_dir($repoPath . '/.git') && !\is_file($repoPath . '/.git')) {
			throw new \RuntimeException("Repo path is not a git clone: {$repoPath}");
		}

		$baseDir = \dirname($repoPath);
		$worktreesDir = $baseDir . '/worktrees';
		$worktreePath = $worktreesDir . '/task-' . $taskId;
		$branch = 'autonomy/task-' . $taskId;
		$remoteRef = 'origin/' . $defaultBranch;

		if (!\is_dir($worktreesDir)) {
			$this->run(['mkdir', '-p', $worktreesDir]);
		}

		if (\is_dir($worktreePath)) {
			return $worktreePath;
		}

		$this->run(['git', '-C', $repoPath, 'fetch', 'origin']);
		$this->run([
			'git', '-C', $repoPath, 'worktree', 'add', '-B', $branch,
			$worktreePath, $remoteRef,
		]);

		return $worktreePath;
	}

	public function currentBranch(string $repoPath): ?string
	{
		$process = new Process(['git', '-C', \rtrim($repoPath, '/'), 'rev-parse', '--abbrev-ref', 'HEAD']);
		$process->setTimeout(15);
		$process->run();

		if (!$process->isSuccessful()) {
			return null;
		}

		$branch = Strings::trim($process->getOutput());

		return $branch === '' ? null : $branch;
	}

	public function removeWorktree(string $repoPath, int $taskId): void
	{
		$repoPath = \rtrim($repoPath, '/');
		$worktreePath = \dirname($repoPath) . '/worktrees/task-' . $taskId;

		if (!\is_dir($worktreePath)) {
			return;
		}

		$this->run(['git', '-C', $repoPath, 'worktree', 'remove', '--force', $worktreePath]);
	}

	/**
	 * @param array<int, string> $command
	 */
	private function run(array $command): void
	{
		$process = new Process($command);
		$process->setTimeout(300);
		$process->run();

		if (!$process->isSuccessful()) {
			throw new ProcessFailedException($process);
		}
	}
}
