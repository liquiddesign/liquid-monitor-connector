<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Triage\Agent;

use InvalidArgumentException;

final class AgentRegistry
{
	/** @var array<string, \LiquidMonitorConnector\Triage\Agent\AgentInterface> */
	private array $agents = [];

	/**
	 * @param array<int, \LiquidMonitorConnector\Triage\Agent\AgentInterface> $agents
	 */
	public function __construct(array $agents = [])
	{
		foreach ($agents as $agent) {
			$this->register($agent);
		}
	}

	public function register(AgentInterface $agent): void
	{
		$this->agents[$agent->name()] = $agent;
	}

	public function forProvider(string $name): AgentInterface
	{
		if (!isset($this->agents[$name])) {
			throw new InvalidArgumentException(\sprintf(
				"Unknown triage provider '%s'. Registered: %s",
				$name,
				\implode(', ', \array_keys($this->agents)) ?: '(none)',
			));
		}

		return $this->agents[$name];
	}

	public function has(string $name): bool
	{
		return isset($this->agents[$name]);
	}

	/**
	 * @return array<int, string>
	 */
	public function names(): array
	{
		return \array_keys($this->agents);
	}
}
