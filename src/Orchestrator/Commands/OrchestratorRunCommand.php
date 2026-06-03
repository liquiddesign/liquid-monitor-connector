<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator\Commands;

use GuzzleHttp\Exception\GuzzleException;
use LiquidMonitorConnector\Orchestrator\JsonMilestoneParser;
use LiquidMonitorConnector\Orchestrator\MonitorClient;
use LiquidMonitorConnector\Orchestrator\PathGuard;
use LiquidMonitorConnector\Orchestrator\PendingMessageDeliverer;
use LiquidMonitorConnector\Orchestrator\SessionSuspender;
use LiquidMonitorConnector\Orchestrator\TaskRunner;
use LiquidMonitorConnector\Orchestrator\TmuxClaudeDriver;
use LiquidMonitorConnector\Orchestrator\TurnCoordinator;
use LiquidMonitorConnector\Orchestrator\WorktreeCleanup;
use LiquidMonitorConnector\Orchestrator\WorktreeManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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

		try {
			$pausePayload = $this->monitor->globalPause();
			$paused = (bool) ($pausePayload['global_pause'] ?? $pausePayload['data']['global_pause'] ?? false);

			if ($paused) {
				$output->writeln('<info>Orchestrator globally paused.</info>');

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
		$settings = \is_array($poll['orchestrator_settings'] ?? null) ? $poll['orchestrator_settings'] : [];
		$tmux = new TmuxClaudeDriver($this->claudeBinary);
		$coordinator = new TurnCoordinator(
			$this->monitor,
			$tmux,
			new JsonMilestoneParser(),
			new PathGuard(),
			$this->turnTimeoutSeconds,
		);

		try {
			if ($repoPath !== '') {
				$cleanup = new WorktreeCleanup($this->monitor, new WorktreeManager(), $repoPath);
				$cleanup->cleanupArchived($this->monitor->listSessionsNeedingWorktreeCleanup(), $output);
			}

			$suspender = new SessionSuspender($this->monitor, $tmux);
			$suspender->suspendIdleRunning($this->monitor->listRunningSessions(), $settings, $output);

			$deliverer = new PendingMessageDeliverer($this->monitor, $tmux, $coordinator);
			$deliverer->deliverAll($this->monitor->listSessionsWithPendingMessages(), $poll, $output);
		} catch (GuzzleException $e) {
			$output->writeln('<error>Pre-poll maintenance failed: ' . $e->getMessage() . '</error>');
		}

		try {
			$poll = $this->monitor->poll($this->workerId, $this->maxConcurrent, [], 0);
		} catch (GuzzleException $e) {
			$output->writeln('<error>Monitor poll failed: ' . $e->getMessage() . '</error>');

			return self::FAILURE;
		}

		/** @var array<int, array<string, mixed>> $tasks */
		$tasks = $poll['tasks'] ?? [];

		if ($tasks === []) {
			$output->writeln('<info>No orchestrator tasks pending.</info>');

			return self::SUCCESS;
		}

		$contextSources = $poll['context_sources'] ?? [];
		$runner = new TaskRunner(
			$this->monitor,
			new WorktreeManager(),
			$tmux,
			$coordinator,
		);

		$failures = 0;

		foreach ($tasks as $task) {
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

		return $failures === 0 ? self::SUCCESS : self::FAILURE;
	}
}
