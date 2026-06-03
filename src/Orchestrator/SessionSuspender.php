<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Symfony\Component\Console\Output\OutputInterface;

final class SessionSuspender
{
	public function __construct(private readonly MonitorClient $monitor, private readonly TmuxClaudeDriver $tmux,)
	{
	}

	/**
	 * @param array<int, array<string, mixed>> $sessions
	 * @param array<string, mixed> $orchestratorSettings
	 */
	public function suspendIdleRunning(array $sessions, array $orchestratorSettings, OutputInterface $output): void
	{
		$idleMinutes = (int) ($orchestratorSettings['sleep_after_idle_minutes'] ?? 15);

		if ($idleMinutes <= 0) {
			return;
		}

		$cutoff = (new \DateTimeImmutable())->modify('-' . $idleMinutes . ' minutes');

		foreach ($sessions as $session) {
			if (($session['state'] ?? '') !== 'running') {
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
		}
	}
}
