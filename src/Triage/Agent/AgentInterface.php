<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Triage\Agent;

interface AgentInterface
{
	/**
	 * Provider key matching server's AgentRegistry (e.g. 'anthropic', 'cursor').
	 */
	public function name(): string;

	/**
	 * Run the agent in the current working directory with the given context
	 * (env vars, allowed tools, additional readable directories) received from
	 * the monitor's context_sources. The connector does NOT create or modify
	 * any filesystem state — filesystem access is governed purely by these
	 * server-supplied parameters.
	 * @param array<string, string> $env
	 * @param array<int, string> $allowedTools
	 * @param array<int, string> $addDirs absolute, host-visible paths the agent may read
	 */
	public function run(
		string $prompt,
		array $env,
		array $allowedTools,
		array $addDirs,
		int $timeoutSeconds,
	): AgentResult;
}
