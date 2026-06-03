<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\Arrays;
use Symfony\Component\Console\Output\OutputInterface;

final class SessionSuspender
{
	public function __construct(
		private readonly MonitorClient $monitor,
		private readonly TmuxClaudeDriver $tmux,
		private readonly TurnStateStore $turnStates,
	) {
	}

	/**
	 * @param array<int, array<string, mixed>> $sessions
	 * @param array<string, mixed> $orchestratorSettings
	 * @param array<int, int> $skipSessionIds Sessions already handled this run (e.g. just finalized).
	 */
	public function suspendIdleRunning(
		array $sessions,
		array $orchestratorSettings,
		OutputInterface $output,
		array $skipSessionIds = [],
	): int {
		$idleMinutes = (int) ($orchestratorSettings['sleep_after_idle_minutes'] ?? 15);

		if ($idleMinutes <= 0) {
			return 0;
		}

		$cutoff = (new \DateTimeImmutable())->modify('-' . $idleMinutes . ' minutes');
		$suspended = 0;

		foreach ($sessions as $session) {
			if (($session['state'] ?? '') !== 'running') {
				continue;
			}

			if (Arrays::contains($skipSessionIds, (int) ($session['id'] ?? 0))) {
				continue;
			}

			$worktree = (string) ($session['worktree_path'] ?? $session['claude_session_cwd'] ?? '');

			// Mid-turn sessions are owned by TurnCollector (nudge/timeout), never idle-suspended.
			if ($worktree !== '' && $this->turnStates->read($worktree) !== null) {
				continue;
			}

			$lastActivity = (string) ($session['last_activity_at'] ?? '');

			if ($lastActivity === '') {
				continue;
			}

			$last = new \DateTimeImmutable($lastActivity);

			if ($last > $cutoff) {
				continue;
			}

			$sessionId = (int) ($session['id'] ?? 0);
			$tmuxName = (string) ($session['tmux_session_name'] ?? '');

			if ($tmuxName !== '') {
				$this->tmux->kill($tmuxName);
			}

			$this->monitor->patchSession($sessionId, [
				'state' => 'suspended',
				'suspended_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
			]);

			$output->writeln(\sprintf('<info>Suspended idle session #%d (%s)</info>', $sessionId, $tmuxName));
			$suspended++;
		}

		return $suspended;
	}
}
