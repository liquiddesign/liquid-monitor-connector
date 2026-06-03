<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Human-readable progress lines for orchestrator:run (run.sh).
 */
final class OrchestratorRunReporter
{
	public function __construct(private readonly OutputInterface $output)
	{
	}

	public function runHeader(string $workerId, int $capacity, string $repoPath): void
	{
		$this->output->writeln('');
		$this->output->writeln('<comment>── Orchestrator run ─────────────────────────────</comment>');
		$this->output->writeln(\sprintf('Worker <info>%s</info> · capacity <info>%d</info> new task(s) per run', $workerId, $capacity));

		if ($repoPath === '') {
			return;
		}

		$this->output->writeln(\sprintf('Repo  <info>%s</info>', $repoPath));
	}

	/**
	 * @param array<int, array<string, mixed>> $leasedTasks
	 * @param array<string, mixed>|null $queueSummary
	 */
	public function afterPoll(array $leasedTasks, ?array $queueSummary): void
	{
		if ($queueSummary !== null) {
			$this->writeQueueSummary('Monitor queue', $queueSummary);
		}

		$count = \count($leasedTasks);

		if ($count === 0) {
			$this->output->writeln('<comment>Poll: no new tasks leased this run.</comment>');

			return;
		}

		$this->output->writeln(\sprintf('<info>Poll: leased %d task(s) to start now:</info>', $count));

		foreach ($leasedTasks as $task) {
			$this->output->writeln($this->formatTaskLine($task, '  → '));
		}
	}

	public function maintenanceStart(int $worktreesToClean, int $runningSessions, int $sessionsWithPendingMessages): void
	{
		$this->output->writeln('');
		$this->output->writeln('<comment>── Maintenance ──────────────────────────────────</comment>');
		$this->output->writeln(\sprintf(
			'Checking: <info>%d</info> archived worktree(s), <info>%d</info> running session(s), <info>%d</info> session(s) with queued messages',
			$worktreesToClean,
			$runningSessions,
			$sessionsWithPendingMessages,
		));
	}

	public function maintenanceIdle(): void
	{
		$this->output->writeln('<comment>Maintenance: nothing to clean, suspend, or deliver.</comment>');
	}

	public function maintenanceDone(int $worktreesRemoved, int $sessionsSuspended, int $messagesDelivered, int $turnsFinalized = 0): void
	{
		if ($worktreesRemoved === 0 && $sessionsSuspended === 0 && $messagesDelivered === 0 && $turnsFinalized === 0) {
			$this->maintenanceIdle();

			return;
		}

		$this->output->writeln(\sprintf(
			'<info>Maintenance done:</info> %d turn(s) finalized, %d worktree(s) removed, %d session(s) suspended, %d message(s) delivered',
			$turnsFinalized,
			$worktreesRemoved,
			$sessionsSuspended,
			$messagesDelivered,
		));
	}

	/**
	 * @param array<int, array<string, mixed>> $runningSessions
	 * @param array<string, mixed>|null $queueSummary
	 */
	public function idleNoNewTasks(?array $queueSummary, array $runningSessions): void
	{
		$this->output->writeln('');
		$this->output->writeln('<comment>── This run ─────────────────────────────────────</comment>');
		$this->output->writeln('<comment>No new tasks started in this run.</comment>');

		if ($queueSummary !== null) {
			$stuck = (int) ($queueSummary['in_progress_without_agent_session'] ?? 0);

			if ($stuck > 0) {
				$this->output->writeln(\sprintf(
					'<error>Warning: %d task(s) are in_progress on the monitor but have no agent session</error> (often after a failed run — reset to pending in Filament or `triage:cleanup-stuck`).',
					$stuck,
				));
			}

			$other = (int) ($queueSummary['in_progress_other_worker'] ?? 0);

			if ($other > 0) {
				$this->output->writeln(\sprintf(
					'<comment>%d task(s) in_progress on another worker — this host will not pick them up.</comment>',
					$other,
				));
			}
		}

		if ($runningSessions !== []) {
			$this->output->writeln(\sprintf('<info>%d agent session(s) still running on this project:</info>', \count($runningSessions)));

			foreach ($runningSessions as $session) {
				$taskId = (int) ($session['triage_task_id'] ?? 0);
				$tmux = (string) ($session['tmux_session_name'] ?? '');
				/** @var array<string, mixed>|null $triageTask */
				$triageTask = \is_array($session['triage_task'] ?? null) ? $session['triage_task'] : null;
				$title = (string) ($triageTask['external_title'] ?? '');

				$this->output->writeln(\sprintf(
					'  · session #%d task #%d %s tmux=<info>%s</info> (attach: tmux attach -t %s)',
					(int) ($session['id'] ?? 0),
					$taskId,
					$title !== '' ? \sprintf('«%s»', $title) : '',
					$tmux,
					$tmux,
				));
			}

			return;
		}

		$running = (int) ($queueSummary['agent_sessions_running'] ?? 0);
		$suspended = (int) ($queueSummary['agent_sessions_suspended'] ?? 0);

		if ($running > 0 || $suspended > 0) {
			$this->output->writeln(\sprintf(
				'Active agent sessions: <info>%d</info> running, <info>%d</info> suspended (may be on another host).',
				$running,
				$suspended,
			));
		} else {
			$this->output->writeln('<comment>No agent sessions running — waiting for new pending tasks from triage:scan.</comment>');
		}
	}

	public function startingTasks(int $count): void
	{
		$this->output->writeln('');
		$this->output->writeln(\sprintf('<info>Starting %d new task(s)…</info>', $count));
	}

	/**
	 * @param array<string, mixed> $task
	 */
	public function taskStarting(array $task, string $tmuxName, string $worktreePath): void
	{
		$taskId = (int) ($task['id'] ?? 0);
		$title = (string) ($task['external_title'] ?? '');
		$ticket = (string) ($task['ticket_number'] ?? '');

		$this->output->writeln(\sprintf(
			'<info>Task #%d %s%s: creating worktree + tmux <info>%s</info></info>',
			$taskId,
			$ticket !== '' ? $ticket . ' ' : '',
			$title !== '' ? '«' . $title . '»' : '',
			$tmuxName,
		));
		$this->output->writeln(\sprintf('  Worktree: <comment>%s</comment>', $worktreePath));
		$this->output->writeln(\sprintf('  Watch:    <comment>tmux attach -t %s</comment>', $tmuxName));
	}

	public function runFinished(int $started, int $failed): void
	{
		$this->output->writeln('');

		if ($started === 0) {
			return;
		}

		if ($failed === 0) {
			$this->output->writeln(\sprintf('<info>Run finished: %d task(s) processed successfully.</info>', $started));

			return;
		}

		$this->output->writeln(\sprintf(
			'<error>Run finished with errors: %d succeeded, %d failed.</error>',
			$started - $failed,
			$failed,
		));
	}

	/**
	 * @param array<string, mixed> $queueSummary
	 */
	private function writeQueueSummary(string $heading, array $queueSummary): void
	{
		$this->output->writeln('');
		$this->output->writeln('<comment>' . $heading . '</comment>');
		$this->output->writeln(\sprintf(
			'  Pending: <info>%d</info> · In progress: <info>%d</info> (this worker: <info>%d</info>) · Sessions: <info>%d</info> running / <info>%d</info> suspended (max <info>%d</info>)',
			(int) ($queueSummary['pending'] ?? 0),
			(int) ($queueSummary['in_progress'] ?? 0),
			(int) ($queueSummary['in_progress_this_worker'] ?? 0),
			(int) ($queueSummary['agent_sessions_running'] ?? 0),
			(int) ($queueSummary['agent_sessions_suspended'] ?? 0),
			(int) ($queueSummary['max_concurrent_sessions'] ?? 0),
		));

		$pendingMessages = (int) ($queueSummary['sessions_with_pending_messages'] ?? 0);

		if ($pendingMessages <= 0) {
			return;
		}

		$this->output->writeln(\sprintf(
			'  <info>%d</info> session(s) have messages waiting for delivery',
			$pendingMessages,
		));
	}

	/**
	 * @param array<string, mixed> $task
	 */
	private function formatTaskLine(array $task, string $prefix): string
	{
		$id = (int) ($task['id'] ?? 0);
		$title = (string) ($task['external_title'] ?? '');
		$ticket = (string) ($task['ticket_number'] ?? '');
		$source = (string) ($task['source'] ?? '');

		return \sprintf(
			'%s#%d %s%s%s',
			$prefix,
			$id,
			$ticket !== '' ? $ticket . ' ' : '',
			$title !== '' ? '«' . $title . '»' : '',
			$source !== '' ? ' (' . $source . ')' : '',
		);
	}
}
