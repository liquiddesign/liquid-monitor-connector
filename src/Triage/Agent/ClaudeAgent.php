<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Triage\Agent;

use Symfony\Component\Process\Process;

final class ClaudeAgent implements AgentInterface
{
	public function __construct(private readonly string $binary = 'claude', private readonly int $maxTurns = 15)
	{
	}

	public function name(): string
	{
		return 'anthropic';
	}

	public function run(
		string $prompt,
		array $env,
		array $allowedTools,
		array $addDirs,
		int $timeoutSeconds,
	): AgentResult {
		$command = [
			$this->binary,
			'-p', $prompt,
			'--output-format=stream-json',
			'--max-turns', (string) $this->maxTurns,
			'--dangerously-skip-permissions',
		];

		if ($allowedTools !== []) {
			$command[] = '--allowed-tools';
			$command[] = \implode(',', \array_unique($allowedTools));
		}

		foreach (\array_unique($addDirs) as $dir) {
			$command[] = '--add-dir';
			$command[] = $dir;
		}

		$process = new Process(
			command: $command,
			env: $env !== [] ? $env : null,
			timeout: $timeoutSeconds,
		);
		$process->run();

		return new AgentResult(
			stdout: $process->getOutput(),
			stderr: $process->getErrorOutput(),
			exitCode: $process->getExitCode() ?? 1,
		);
	}
}
