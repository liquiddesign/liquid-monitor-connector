<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\Strings;
use Symfony\Component\Process\Process;

/**
 * Keeps the working repository fresh before a task starts. In repo mode the agent
 * works directly on the checked-out branch, so the orchestrator (not the agent)
 * pulls the latest code with a fast-forward only. The orchestrator control files
 * under .orchestrator/ are excluded from the clean check.
 */
final class RepoSynchronizer
{
	private const int TIMEOUT = 300;

	/**
	 * True when the working tree has no modified tracked files outside .orchestrator/.
	 *
	 * Untracked files are ignored (--untracked-files=no): they never appear in the
	 * agent's `git diff HEAD` (the diff stat / PathGuard basis), so they cannot
	 * pollute a turn's diff — and they routinely exist after deployment (the
	 * orchestrator skill under .claude/, local notes). Blocking on them used to
	 * silently deadlock the worker ("returning task to queue" every run) for a
	 * file that has zero effect on the agent's output. Modified tracked files
	 * still make the tree dirty, since those would mix into the diff.
	 */
	public function isClean(string $repoPath): bool
	{
		$process = $this->git($repoPath, ['status', '--porcelain', '--untracked-files=no', '--', '.', ':!.orchestrator']);
		$process->run();

		if (!$process->isSuccessful()) {
			return false;
		}

		return Strings::trim($process->getOutput()) === '';
	}

	/**
	 * Fast-forward the current branch to its upstream. Returns false on divergence,
	 * detached HEAD, missing upstream or a network/timeout failure.
	 */
	public function pullFastForward(string $repoPath): bool
	{
		$process = $this->git($repoPath, ['pull', '--ff-only']);
		$process->run();

		return $process->isSuccessful();
	}

	/**
	 * Refresh remote-tracking refs without touching the working tree.
	 */
	public function fetch(string $repoPath): bool
	{
		$process = $this->git($repoPath, ['fetch', 'origin']);
		$process->run();

		return $process->isSuccessful();
	}

	/**
	 * @param array<int, string> $args
	 */
	private function git(string $repoPath, array $args): Process
	{
		$process = new Process(['git', '-C', \rtrim($repoPath, '/'), ...$args]);
		$process->setTimeout(self::TIMEOUT);

		return $process;
	}
}
