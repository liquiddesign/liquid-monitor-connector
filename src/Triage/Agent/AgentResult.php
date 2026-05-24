<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Triage\Agent;

final class AgentResult
{
	public function __construct(
		public readonly string $stdout,
		public readonly string $stderr,
		public readonly int $exitCode,
	) {
	}

	public function isSuccess(): bool
	{
		return $this->exitCode === 0;
	}
}
