<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Triage\Agent;

use Symfony\Component\Process\Process;

/**
 * Cursor agent CLI has no direct equivalents for Claude's --allowed-tools or --add-dir flags.
 * MCP servers and skills/rules are configured per-host via ~/.cursor/ — those parameters
 * are accepted to keep the AgentInterface uniform but the Cursor binary ignores them.
 */
final class CursorAgent implements AgentInterface
{
	public function __construct(private readonly string $binary = 'cursor-agent', private readonly ?string $model = null)
	{
	}

	public function name(): string
	{
		return 'cursor';
	}

	public function run(
		string $prompt,
		array $env,
		array $allowedTools,
		array $addDirs,
		int $timeoutSeconds,
	): AgentResult {
		// Cursor CLI has no per-call equivalents for these; MCP and skills are
		// configured per-host via ~/.cursor/. Accept them for interface symmetry.
		unset($allowedTools, $addDirs);

		$command = [
			$this->binary,
			'-p',
			'--print',
			'--output-format=stream-json',
			'--force',
			'--trust',
		];

		if ($this->model !== null) {
			$command[] = '--model';
			$command[] = $this->model;
		}

		$command[] = $prompt;

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
