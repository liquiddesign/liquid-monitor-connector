<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\Strings;
use Symfony\Component\Process\Process;

final class PathGuard
{
	/**
	 * @param array<string, mixed> $orchestratorSettings
	 */
	public function touchesRestrictedPaths(string $worktreePath, array $orchestratorSettings): bool
	{
		/** @var array<int, string> $patterns */
		$patterns = $orchestratorSettings['require_human_for_paths'] ?? ['migrations', 'config', '.env'];

		if ($worktreePath === '' || !\is_dir($worktreePath) || $patterns === []) {
			return false;
		}

		// `git status --porcelain` (not `git diff HEAD`) so that NEWLY CREATED files
		// count too: a brand-new migration / config / .env is untracked and would be
		// invisible to `git diff HEAD`, slipping past the human-review gate entirely —
		// the most dangerous case. The .orchestrator/ control dir is excluded.
		//
		// In repo-mode this may also see pre-existing untracked files that happen to
		// match a pattern; that only causes an extra (safe) handoff, never a miss.
		$process = new Process(
			['git', '-C', $worktreePath, 'status', '--porcelain', '--untracked-files=all', '--', '.', ':!.orchestrator'],
			null,
			null,
			null,
			30,
		);
		$process->run();

		if (!$process->isSuccessful()) {
			// Fail closed: if git cannot tell us what changed, assume restricted paths were touched.
			return true;
		}

		foreach ($this->changedPaths($process->getOutput()) as $file) {
			foreach ($patterns as $pattern) {
				if (\str_contains($file, $pattern)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Extract file paths from `git status --porcelain` output. Handles untracked
	 * (`?? path`), modified/added/deleted, and renames (`R old -> new`, where the
	 * destination path is the one that matters).
	 * @return array<int, string>
	 */
	private function changedPaths(string $porcelain): array
	{
		$paths = [];

		foreach (\preg_split('/\r?\n/', Strings::trim($porcelain)) ?: [] as $line) {
			if (Strings::length($line) <= 3) {
				continue;
			}

			// Drop the two status columns + separating space.
			$path = Strings::substring($line, 3);

			// Renames/copies are reported as "orig -> dest"; the destination is what changed.
			$arrow = Strings::indexOf($path, ' -> ');

			if ($arrow !== null) {
				$path = Strings::substring($path, $arrow + 4);
			}

			// git quotes paths with special characters in double quotes — strip them.
			$path = Strings::trim($path, ' "');

			if ($path === '') {
				continue;
			}

			$paths[] = $path;
		}

		return $paths;
	}
}
