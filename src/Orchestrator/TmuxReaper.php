<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Kills orphan orchestrator tmux sessions — panes whose monitor session is no
 * longer running or expecting messages. Only touches sessions named
 * orch-p{projectId}-t* for THIS project, so multiple projects can share a host.
 * Legacy orch-{id} sessions don't match the prefix and are left alone.
 */
final class TmuxReaper
{
	public function __construct(private readonly TmuxClaudeDriver $tmux)
	{
	}

	/**
	 * @param array<int, string> $whitelist tmux session names that must stay alive
	 */
	public function reap(array $whitelist, int $projectId, OutputInterface $output): int
	{
		$process = new Process(['tmux', 'ls', '-F', '#{session_name}']);
		$process->setTimeout(15);
		$process->run();

		// No tmux server / no sessions — nothing to reap.
		if (!$process->isSuccessful()) {
			return 0;
		}

		$all = \preg_split('/\r?\n/', Strings::trim($process->getOutput())) ?: [];
		$orphans = self::selectOrphans($all, $whitelist, $projectId);

		foreach ($orphans as $name) {
			$output->writeln(\sprintf('<comment>Reaping orphan tmux session %s.</comment>', $name));
			$this->tmux->kill($name);
		}

		return \count($orphans);
	}

	/**
	 * Pure selection logic, separated for testability: sessions with this
	 * project's prefix that are not whitelisted.
	 * @param array<int, string> $allSessions
	 * @param array<int, string> $whitelist
	 * @return array<int, string>
	 */
	public static function selectOrphans(array $allSessions, array $whitelist, int $projectId): array
	{
		$prefix = \sprintf('orch-p%d-', $projectId);
		$orphans = [];

		foreach ($allSessions as $name) {
			if ($name === '' || !\str_starts_with($name, $prefix)) {
				continue;
			}

			if (Arrays::contains($whitelist, $name)) {
				continue;
			}

			$orphans[] = $name;
		}

		return $orphans;
	}
}
