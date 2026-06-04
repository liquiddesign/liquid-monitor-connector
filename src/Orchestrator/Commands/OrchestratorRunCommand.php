<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator\Commands;

use GuzzleHttp\Exception\GuzzleException;
use LiquidMonitorConnector\Orchestrator\ContextBundles;
use LiquidMonitorConnector\Orchestrator\JsonMilestoneParser;
use LiquidMonitorConnector\Orchestrator\MonitorClient;
use LiquidMonitorConnector\Orchestrator\OrchestratorRunReporter;
use LiquidMonitorConnector\Orchestrator\Outbox;
use LiquidMonitorConnector\Orchestrator\PathGuard;
use LiquidMonitorConnector\Orchestrator\PendingMessageDeliverer;
use LiquidMonitorConnector\Orchestrator\PolicySettingsWriter;
use LiquidMonitorConnector\Orchestrator\RepoSynchronizer;
use LiquidMonitorConnector\Orchestrator\RunLock;
use LiquidMonitorConnector\Orchestrator\SessionSuspender;
use LiquidMonitorConnector\Orchestrator\TaskRunner;
use LiquidMonitorConnector\Orchestrator\TmuxClaudeDriver;
use LiquidMonitorConnector\Orchestrator\TmuxReaper;
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
	/**
	 * Capacity / claude binary / turn timeout are normally configured on the monitor
	 * (orchestrator_settings) — the nullable constructor args are optional local
	 * overrides for debugging.
	 */
	public function __construct(
		private readonly MonitorClient $monitor,
		private readonly string $workerId,
		private readonly ?int $maxConcurrent = null,
		private readonly ?string $claudeBinary = null,
		private readonly ?int $turnTimeoutSeconds = null,
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

			$poll = $this->monitor->poll($this->workerId, $this->maxConcurrent);
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
		$projectId = (int) ($poll['project_id'] ?? 0);
		/** @var array<string, mixed>|null $queueSummary */
		$queueSummary = \is_array($poll['queue_summary'] ?? null) ? $poll['queue_summary'] : null;

		$settings = \is_array($poll['orchestrator_settings'] ?? null) ? $poll['orchestrator_settings'] : [];

		// Local overrides win, otherwise the monitor's orchestrator_settings decide.
		$claudeBinary = $this->claudeBinary
			?? (\is_string($settings['claude_binary'] ?? null) && $settings['claude_binary'] !== '' ? $settings['claude_binary'] : 'claude');
		$turnTimeoutSeconds = $this->turnTimeoutSeconds
			?? (\is_numeric($settings['turn_timeout_seconds'] ?? null) ? (int) $settings['turn_timeout_seconds'] : 900);

		$claudeError = $this->checkClaudeBinary($claudeBinary, $output);

		if ($claudeError !== null) {
			$output->writeln('<error>' . $claudeError . '</error>');

			return self::FAILURE;
		}

		$reporter->runHeader($this->workerId, $this->maxConcurrent, $repoPath);

		/** @var array<int, array<string, mixed>> $leasedTasks */
		$leasedTasks = $poll['tasks'] ?? [];
		$reporter->afterPoll($leasedTasks, $queueSummary);

		$tmux = new TmuxClaudeDriver($claudeBinary);
		$parser = new JsonMilestoneParser();
		$turnStates = new TurnStateStore();
		$bundles = new ContextBundles($this->monitor->monitorUrl(), $this->monitor->apiKey());
		$policyWriter = new PolicySettingsWriter();
		$outbox = new Outbox();
		$coordinator = new TurnCoordinator(
			$this->monitor,
			$tmux,
			new PathGuard(),
			$turnStates,
			$outbox,
		);
		$collector = new TurnCollector(
			$this->monitor,
			$tmux,
			$parser,
			$coordinator,
			$turnStates,
			$turnTimeoutSeconds,
		);

		$worktreesRemoved = 0;
		$turnsFinalized = 0;
		$sessionsSuspended = 0;
		$messagesDelivered = 0;

		try {
			// Replay finalizations that a previous run could not deliver (monitor outage).
			if ($repoPath !== '') {
				$outbox->flush(\rtrim($repoPath, '/') . '/' . Outbox::OUTBOX_RELATIVE_PATH, $this->monitor, $output);
			}

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

			// Reap orphan panes before the deliverer resumes suspended sessions —
			// anything alive now that is neither running nor awaiting a message is a leak.
			if ($projectId > 0) {
				$whitelist = [];

				foreach ([...$runningSessions, ...$pendingMessageSessions] as $aliveSession) {
					$name = (string) ($aliveSession['tmux_session_name'] ?? '');

					if ($name === '') {
						continue;
					}

					$whitelist[] = $name;
				}

				$reaper = new TmuxReaper($tmux);
				$reaper->reap(\array_values(\array_unique($whitelist)), $projectId, $output);
			}

			$deliverer = new PendingMessageDeliverer($this->monitor, $tmux, $coordinator, $turnStates, $bundles, $policyWriter);
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
			$policyWriter,
			new RepoSynchronizer(),
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
	 * Verify host binaries before doing anything. Uses a login shell so PATH
	 * matches what the tmux panes will see. The claude binary is checked later,
	 * after the poll — its name comes from orchestrator_settings.
	 */
	private function preflightCheck(): ?string
	{
		foreach (['git', 'tmux'] as $binary) {
			$process = new Process(['bash', '-lc', 'command -v ' . \escapeshellarg($binary)]);
			$process->setTimeout(15);
			$process->run();

			if (!$process->isSuccessful()) {
				return \sprintf('Required binary not found in PATH: %s', $binary);
			}
		}

		return null;
	}

	/**
	 * Resolve the claude binary (name comes from orchestrator_settings) and warn
	 * when the version predates --settings/hooks support.
	 */
	private function checkClaudeBinary(string $claudeBinary, OutputInterface $output): ?string
	{
		$process = new Process(['bash', '-lc', 'command -v ' . \escapeshellarg($claudeBinary)]);
		$process->setTimeout(15);
		$process->run();

		if (!$process->isSuccessful()) {
			return \sprintf('Required binary not found in PATH: %s', $claudeBinary);
		}

		$version = new Process(['bash', '-lc', \escapeshellarg($claudeBinary) . ' --version']);
		$version->setTimeout(15);
		$version->run();

		if (!$version->isSuccessful()
			|| \preg_match('/(\d+)\.(\d+)\.(\d+)/', $version->getOutput(), $matches) !== 1) {
			$output->writeln('<comment>Could not determine claude version — policy enforcement assumes --settings/hooks support.</comment>');

			return null;
		}

		if ((int) $matches[1] < 2) {
			$output->writeln(\sprintf(
				'<comment>claude %s is older than the tested 2.x line — verify that --settings files with hooks are supported.</comment>',
				$matches[0],
			));
		}

		return null;
	}
}
