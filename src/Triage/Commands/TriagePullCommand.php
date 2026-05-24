<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Triage\Commands;

use GuzzleHttp\Exception\GuzzleException;
use LiquidMonitorConnector\Triage\Agent\AgentRegistry;
use LiquidMonitorConnector\Triage\MonitorClient;
use LiquidMonitorConnector\Triage\ResultExtractor;
use Nette\Utils\Strings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'triage:pull', description: 'Poll Liquid Monitor for triage tasks and run them via the configured agent CLI.')]
final class TriagePullCommand extends Command
{
	public function __construct(
		private readonly MonitorClient $monitor,
		private readonly AgentRegistry $agents,
		private readonly ResultExtractor $extractor,
		private readonly string $workerId,
		private readonly int $maxConcurrent = 1,
		private readonly int $agentTimeoutSeconds = 600,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		unset($input);

		try {
			$work = $this->monitor->pollWork($this->workerId, $this->maxConcurrent, []);
		} catch (GuzzleException $e) {
			$output->writeln('<error>Monitor poll failed: ' . $e->getMessage() . '</error>');

			return self::FAILURE;
		}

		if (($work['paused'] ?? false) === true) {
			$output->writeln('<info>Triage paused globally.</info>');

			return self::SUCCESS;
		}

		$tasks = $work['tasks'] ?? [];

		if ($tasks === []) {
			$output->writeln('<info>No triage tasks pending.</info>');

			return self::SUCCESS;
		}

		$agentInfo = $work['agent'] ?? null;
		$providerKey = \is_array($agentInfo) ? ($agentInfo['key'] ?? null) : null;

		if (!\is_string($providerKey) || $providerKey === '') {
			$output->writeln('<error>Monitor did not return an agent provider key — cannot dispatch tasks.</error>');

			return self::FAILURE;
		}

		if (!$this->agents->has($providerKey)) {
			$output->writeln(\sprintf(
				'<error>Provider "%s" is not registered locally. Registered: %s.</error>',
				$providerKey,
				\implode(', ', $this->agents->names()) ?: '(none)',
			));

			return self::FAILURE;
		}

		$contextSources = $work['context_sources'] ?? [];
		$failures = 0;

		foreach ($tasks as $task) {
			try {
				$this->processTask($task, $contextSources, $providerKey, $output);
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

	/**
	 * @param array<string, mixed> $task
	 * @param array<int, array<string, mixed>> $contextSources
	 */
	private function processTask(array $task, array $contextSources, string $providerKey, OutputInterface $output): void
	{
		$taskId = (int) ($task['id'] ?? 0);

		if ($taskId <= 0) {
			throw new \RuntimeException('Task is missing numeric id.');
		}

		$env = [];
		$allowedTools = [];
		$addDirs = [];
		$skillBlocks = [];

		foreach ($contextSources as $source) {
			if (\is_string($source['skill_md'] ?? null) && $source['skill_md'] !== '') {
				$type = (string) ($source['type'] ?? 'context');
				$skillBlocks[] = "## skill: {$type}\n\n" . $source['skill_md'];
			}

			foreach (($source['env'] ?? []) as $k => $v) {
				if (\is_string($k) && \is_scalar($v)) {
					$env[$k] = (string) $v;
				}
			}

			foreach (($source['allowed_tools_patterns'] ?? []) as $pattern) {
				if (\is_string($pattern)) {
					$allowedTools[] = $pattern;
				}
			}

			foreach (($source['add_dirs'] ?? []) as $dir) {
				if (\is_string($dir)) {
					$addDirs[] = $dir;
				}
			}
		}

		$prompt = $this->buildPrompt($task, $skillBlocks);
		$agent = $this->agents->forProvider($providerKey);

		$output->writeln(\sprintf('<info>Task #%d: running %s agent…</info>', $taskId, $agent->name()));

		$result = $agent->run(
			prompt: $prompt,
			env: $env,
			allowedTools: $allowedTools,
			addDirs: \array_values(\array_unique($addDirs)),
			timeoutSeconds: $this->agentTimeoutSeconds,
		);

		if (!$result->isSuccess()) {
			$stderr = Strings::trim($result->stderr);

			throw new \RuntimeException(\sprintf(
				'agent "%s" exited with code %d. stderr: %s',
				$agent->name(),
				$result->exitCode,
				$stderr !== '' ? $stderr : '(empty)',
			));
		}

		$payload = $this->extractor->extract($result->stdout);

		if ($payload === null) {
			throw new \RuntimeException('Could not find a ```json result block in agent stdout.');
		}

		$payload['provider'] ??= $agent->name();
		$this->monitor->postResult($taskId, $payload);

		$output->writeln(\sprintf('<info>Task #%d: result posted.</info>', $taskId));
	}

	/**
	 * @param array<string, mixed> $task
	 * @param array<int, string> $skillBlocks
	 */
	private function buildPrompt(array $task, array $skillBlocks): string
	{
		$ticket = (string) ($task['ticket_number'] ?? '#' . ($task['id'] ?? '?'));
		$source = (string) ($task['source'] ?? 'unknown');
		$title = (string) ($task['external_title'] ?? '');
		$url = (string) ($task['external_url'] ?? '');

		$skills = $skillBlocks === [] ? '' : "\n\n# Skills\n\n" . \implode("\n\n", $skillBlocks) . "\n";

		return <<<PROMPT
You are triaging an incoming task ({$ticket}) from source "{$source}".

Title: {$title}
URL: {$url}
{$skills}
Return your final triage analysis as a SINGLE ```json fenced code block and NOTHING ELSE — no preamble, no commentary, no trailing text. The block MUST contain these fields:
  - category (string): one of "answer", "needs_work", "unclear", "error"
  - confidence (string): "low" | "medium" | "high"
  - draft_response_md (string): markdown reply for the human reviewer
  - reasoning_md (string): your reasoning (optional)
  - context_sources (array of strings): what you actually consulted (optional)
  - tools_called (array): {tool:string,count:int} entries (optional)
  - input_tokens (int), output_tokens (int), estimated_cost_usd (number): your usage stats (best-effort)
PROMPT;
	}
}
