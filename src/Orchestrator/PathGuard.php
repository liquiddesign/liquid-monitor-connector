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

		$process = new Process(['git', '-C', $worktreePath, 'diff', '--name-only', 'HEAD'], null, null, null, 30);
		$process->run();

		if (!$process->isSuccessful()) {
			// Fail closed: if git cannot tell us what changed, assume restricted paths were touched.
			return true;
		}

		foreach (\preg_split('/\r?\n/', Strings::trim($process->getOutput())) ?: [] as $file) {
			if ($file === '') {
				continue;
			}

			foreach ($patterns as $pattern) {
				if (\str_contains($file, $pattern)) {
					return true;
				}
			}
		}

		return false;
	}
}
