<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

/**
 * Removes secrets and personal data from the tail of a failed run's log before
 * it leaves the server. The tail ends up in the description of a triage task in
 * LQDeck and from there in an LLM agent's context (docs/v4-local-scheduler.md § 7.4).
 *
 * Registered as `scheduler.logRedactor` (a service reference or a class name).
 */
interface LogRedactor
{
	public function redact(string $log): string;
}
