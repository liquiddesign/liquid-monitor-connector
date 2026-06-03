<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator\Commands;

use GuzzleHttp\Exception\GuzzleException;
use LiquidMonitorConnector\Orchestrator\ContextBundles;
use LiquidMonitorConnector\Orchestrator\JsonMilestoneParser;
use LiquidMonitorConnector\Orchestrator\MonitorClient;
use LiquidMonitorConnector\Orchestrator\OrchestratorRunReporter;
use LiquidMonitorConnector\Orchestrator\PathGuard;
use LiquidMonitorConnector\Orchestrator\PendingMessageDeliverer;
use LiquidMonitorConnector\Orchestrator\RunLock;
use LiquidMonitorConnector\Orchestrator\SessionSuspender;
use LiquidMonitorConnector\Orchestrator\TaskRunner;
use LiquidMonitorConnector\Orchestrator\TmuxClaudeDriver;
use LiquidMonitorConnector\Orchestrator\TurnCollector;
use LiquidMonitorConnector\Orchestrator\TurnCoordinator;
use LiquidMonitorConnector\Orchestrator\TurnStateStore;
use LiquidMonitorConnector\Orchestrator\WorktreeCleanup;
use LiquidMonitorConnector\Orchestrator\WorktreeManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

#[AsCommand(name: 'orchestrator:run', description: 'Poll Liquid Monitor orchestrator API, run tasks in tmux + Claude REPL.')]
final class OrchestratorRunCommand extends Command
{
	public function __construct(
		private readonly MonitorClient $monitor,
		private readonly string $workerId,
		private readonly int $maxConcurrent = 1,
		private readonly string $claudeBinary = 'claude',
		private readonly int $turnTimeoutSeconds = 900,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		unset($input);

		$preflightError = $this->preflightCheck();

		if ($preflightError !== null) {
			$output->writeln('<error>' . $preflightError . '</error>');

			return self::FAILURE;
		}

		$lock = new RunLock($this->workerId);

		if (!$lock->acquire()) {
			$output->writeln('<info>Another orchestrator run is still active — skipping.</info>');

			return self::SUCCESS;
		}

		try {
			return $this->doRun($output);
		} finally {
			$lock->release();
		}
	}

	private function doRun(OutputInterface $output): int
	{
		$reporter = new OrchestratorRunReporter($output);

		try {
			$pausePayload = $this->monitor->globalPause();
			$paused = (bool) ($pausePayload['global_pause'] ?? $pausePayload['data']['global_pause'] ?? false);

			if ($paused) {
				$output->writeln('<info>Orchestrator globally paused on monitor — nothing to do.</info>');

				return self::SUCCESS;
			}

			$poll = $this->monitor->poll($this->workerId, $this->maxConcurrent, [], 0);
		} catch (GuzzleException $e) {
			$output->writeln('<error>Monitor request failed: ' . $e->getMessage() . '</error>');

			return self::FAILURE;
		}

		if (($poll['paused'] ?? false) === true) {
			$output->writeln('<info>Orchestrator paused.</info>');

			return self::SUCCESS;
		}

		if (($poll['orchestrator_enabled'] ?? true) === false) {
			$output->writeln('<info>Orchestrator disabled for this project.</info>');

			return self::SUCCESS;
		}

		$repoPath = (string) ($poll['orchestrator_repo_path'] ?? '');
		/** @var array<string, mixed>|null $queueSummary */
		$queueSummary = \is_array($poll['queue_summary'] ?? null) ? $poll['queue_summary'] : null;

		$reporter->runHeader($this->workerId, $this->maxConcurrent, $repoPath);

		/** @var array<int, array<string, mixed>> $leasedTasks */
		$leasedTasks = $poll['tasks'] ?? [];
		$reporter->afterPoll($leasedTasks, $queueSummary);

		$settings = \is_array($poll['orchestrator_settings'] ?? null) ? $poll['orchestrator_settings'] : [];
		$tmux = new TmuxClaudeDriver($this->claudeBinary);
		$parser = new JsonMilestoneParser();
		$turnStates = new TurnStateStore();
		$bundles = new ContextBundles();
		$coordinator = new TurnCoordinator(
			$this->monitor,
			$tmux,
			new PathGuard(),
			$turnStates,
		);
		$collector = new TurnCollector(
			$this->monitor,
			$tmux,
			$parser,
			$coordinator,
			$turnStates,
			$this->turnTimeoutSeconds,
		);

		$worktreesRemoved = 0;
		$turnsFinalized = 0;
		$sessionsSuspended = 0;
		$messagesDelivered = 0;

		try {
			$toCleanup = $this->monitor->listSessionsNeedingWorktreeCleanup();
			$runningSessions = $this->monitor->listRunningSessions();
			$pendingMessageSessions = $this->monitor->listSessionsWithPendingMessages();

			$reporter->maintenanceStart(
				\count($toCleanup),
				\count($runningSessions),
				\count($pendingMessageSessions),
			);

			if ($repoPath !== '') {
				$cleanup = new WorktreeCleanup($this->monitor, new WorktreeManager(), $repoPath);
				$worktreesRemoved = $cleanup->cleanupArchived($toCleanup, $output);
			}

			$finalizedSessionIds = $collector->collectAll($runningSessions, $poll, $output);
			$turnsFinalized = \count($finalizedSessionIds);

			$suspender = new SessionSuspender($this->monitor, $tmux, $turnStates);
			$sessionsSuspended = $suspender->suspendIdleRunning($runningSessions, $settings, $output, $finalizedSessionIds);

			$deliverer = new PendingMessageDeliverer($this->monitor, $tmux, $coordinator, $turnStates, $bundles);
			$messagesDelivered = $deliverer->deliverAll($pendingMessageSessions, $poll, $output);

			$reporter->maintenanceDone($worktreesRemoved, $sessionsSuspended, $messagesDelivered, $turnsFinalized);
		} catch (GuzzleException $e) {
			$output->writeln('<error>Pre-poll maintenance failed: ' . $e->getMessage() . '</error>');
		}

		if ($leasedTasks === []) {
			$reporter->idleNoNewTasks($queueSummary, $this->monitor->listRunningSessions());
			$reporter->runFinished(0, 0);

			return self::SUCCESS;
		}

		$contextSources = $poll['context_sources'] ?? [];
		$runner = new TaskRunner(
			$this->monitor,
			new WorktreeManager(),
			$tmux,
			$coordinator,
			$turnStates,
			$bundles,
			$reporter,
		);

		$reporter->startingTasks(\count($leasedTasks));

		$failures = 0;

		foreach ($leasedTasks as $task) {
			try {
				$runner->run($task, $contextSources, $poll, $output);
			} catch (Throwable $e) {
				$failures++;
				$output->writeln(\sprintf(
					'<error>Task #%s failed: %s</error>',
					(string) ($task['id'] ?? '?'),
					$e->getMessage(),
				));
			}
		}

		$reporter->runFinished(\count($leasedTasks), $failures);

		return $failures === 0 ? self::SUCCESS : self::FAILURE;
	}

	/**
	 * Verify required binaries before doing anything. Uses a login shell so PATH
	 * matches what the tmux panes will see (claude is often installed via nvm).
	 */
	private function preflightCheck(): ?string
	{
		foreach (['git', 'tmux', $this->claudeBinary] as $binary) {
			$process = new Process(['bash', '-lc', 'command -v ' . \escapeshellarg($binary)]);
			$process->setTimeout(15);
			$process->run();

			if (!$process->isSuccessful()) {
				return \sprintf('Required binary not found in PATH: %s', $binary);
			}
		}

		return null;
	}
}
