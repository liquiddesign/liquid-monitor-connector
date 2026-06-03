<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class TurnCoordinator
{
	/** Where the agent writes its milestone, relative to the worktree root. */
	public const string MILESTONE_RELATIVE_PATH = '.orchestrator/milestone.json';

	public function __construct(
		private readonly MonitorClient $monitor,
		private readonly TmuxClaudeDriver $tmux,
		private readonly PathGuard $pathGuard,
		private readonly TurnStateStore $turnStates,
	) {
	}

	/**
	 * Remove stale milestone + turn-state files before submitting a prompt, so a
	 * later collect pass cannot pick up the previous turn's result (worktrees are reused).
	 */
	public function prepareForTurn(string $worktreePath): void
	{
		$this->turnStates->clear($worktreePath);
		$file = $this->milestoneFilePath($worktreePath);

		if ($file === null || !\is_file($file)) {
			return;
		}

		FileSystem::delete($file);
	}

	public function milestoneFilePath(string $worktreePath): ?string
	{
		$worktreePath = \rtrim($worktreePath, '/');

		return $worktreePath === '' ? null : $worktreePath . '/' . self::MILESTONE_RELATIVE_PATH;
	}

	/**
	 * @param array<string, mixed> $session
	 * @param array<string, mixed> $task
	 * @param array<string, mixed> $pollMeta
	 * @param array<string, mixed> $milestone
	 */
	public function finalizeTurn(
		array $session,
		array $task,
		array $pollMeta,
		array $milestone,
		int $turnNumber,
		OutputInterface $output,
	): void {
		$sessionId = (int) ($session['id'] ?? 0);
		$taskId = (int) ($task['id'] ?? 0);
		$worktreePath = (string) ($session['worktree_path'] ?? $session['claude_session_cwd'] ?? '');
		$tmuxName = (string) ($session['tmux_session_name'] ?? '');
		$settings = \is_array($pollMeta['orchestrator_settings'] ?? null) ? $pollMeta['orchestrator_settings'] : [];

		$category = (string) ($milestone['category'] ?? 'needs_work');

		if ($category === 'implemented' && $this->pathGuard->touchesRestrictedPaths($worktreePath, $settings)) {
			$category = 'handoff';
			$milestone['category'] = 'handoff';
			$milestone['draft_response_md'] = ($milestone['draft_response_md'] ?? '') . "\n\n_(Orchestrator: changed restricted paths — handed off for human review.)_";
		}

		$turnResponse = $this->monitor->createTurn([
			'agent_session_id' => $sessionId,
			'triage_task_id' => $taskId,
			'turn_number' => $turnNumber,
			'phase' => (string) ($milestone['phase'] ?? 'work'),
			'category' => $category,
			'raw_output_json' => $milestone,
		]);

		/** @var array<string, mixed> $turnData */
		$turnData = $turnResponse['data'] ?? $turnResponse;
		$turnId = (int) ($turnData['id'] ?? 0);

		$verified = false;

		if ($category === 'implemented' || ($milestone['requires_test'] ?? false) === true) {
			$verified = $this->runComposerTest($worktreePath, $output);
			$this->monitor->patchTurn($turnId, [
				'orchestrator_verified' => $verified,
				'completed_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
			]);
		} else {
			$this->monitor->patchTurn($turnId, [
				'orchestrator_verified' => true,
				'completed_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
			]);
			$verified = true;
		}

		if (!$verified && $category === 'implemented') {
			$category = 'handoff';
		}

		$gitDiffStat = $this->gitDiffStat($worktreePath);

		$this->monitor->postResult($taskId, [
			'category' => $category,
			'draft_response_md' => (string) ($milestone['draft_response_md'] ?? ''),
			'reasoning_md' => (string) ($milestone['reasoning_md'] ?? ''),
			'confidence' => (string) ($milestone['confidence'] ?? 'medium'),
			'context_sources' => $milestone['context_sources'] ?? [],
			'tools_called' => $milestone['tools_called'] ?? [],
			'input_tokens' => isset($milestone['input_tokens']) ? (int) $milestone['input_tokens'] : null,
			'output_tokens' => isset($milestone['output_tokens']) ? (int) $milestone['output_tokens'] : null,
			'estimated_cost_usd' => isset($milestone['estimated_cost_usd']) ? (float) $milestone['estimated_cost_usd'] : null,
			'provider' => 'anthropic',
			'orchestrator_metadata' => [
				'git_diff_stat' => $gitDiffStat,
				'turn_number' => $turnNumber,
				'verified' => $verified,
			],
		]);

		$this->monitor->patchTask($taskId, [
			'status' => 'completed',
			'finished_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
		]);

		$this->monitor->createEvent([
			'agent_session_id' => $sessionId,
			'triage_task_id' => $taskId,
			'event_type' => 'turn_completed',
			'payload' => ['category' => $category, 'verified' => $verified],
		]);

		if ($tmuxName !== '') {
			$this->tmux->kill($tmuxName);
		}

		$this->monitor->patchSession($sessionId, [
			'state' => 'suspended',
			'suspended_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
			'last_activity_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
		]);

		if ($worktreePath !== '') {
			$this->prepareForTurn($worktreePath);
		}

		$output->writeln(\sprintf('<info>Task #%d: result posted (%s), session suspended.</info>', $taskId, $category));
	}

	private function runComposerTest(string $worktreePath, OutputInterface $output): bool
	{
		if ($worktreePath === '' || !\is_dir($worktreePath)) {
			return false;
		}

		$output->writeln('<info>Running composer test in worktree…</info>');

		$process = new Process(['composer', 'test'], $worktreePath);
		$process->setTimeout(600);
		$process->run();

		if (!$process->isSuccessful()) {
			$output->writeln('<error>composer test failed.</error>');

			return false;
		}

		$output->writeln('<info>composer test passed.</info>');

		return true;
	}

	private function gitDiffStat(string $worktreePath): ?string
	{
		if ($worktreePath === '' || !\is_dir($worktreePath)) {
			return null;
		}

		$process = new Process(['git', '-C', $worktreePath, 'diff', '--stat'], null, null, null, 30);
		$process->run();

		if (!$process->isSuccessful()) {
			return null;
		}

		$output = Strings::trim($process->getOutput());

		return $output === '' ? '(no uncommitted changes)' : $output;
	}
}
