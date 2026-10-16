<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

use Cron\CronExpression;
use LiquidMonitorConnector\Worker\ConcurrencyModeType;
use LiquidMonitorConnector\Worker\PullCron;

/**
 * One cron as declared by its `#[PullCron]` attribute, resolved for an environment.
 */
final class CronDefinition
{
	public const DEFAULT_TIMEOUT = 1000;

	public const DEFAULT_MAX_QUEUE_SIZE = 10;

	/**
	 * @param class-string $handlerClass
	 */
	public function __construct(
		public string $code,
		public string $handlerClass,
		public PullCron $attribute,
		public string|null $environment,
	) {
	}

	/**
	 * Effective expression in this environment; null = not scheduled here.
	 */
	public function schedule(): string|null
	{
		return $this->attribute->scheduleFor($this->environment);
	}

	public function scheduleSource(): string
	{
		return $this->attribute->scheduleSourceFor($this->environment);
	}

	/**
	 * @return list<\Cron\CronExpression> empty when the cron is not scheduled in this environment
	 */
	public function expressions(): array
	{
		return \array_map(
			static fn (string $expression): CronExpression => new CronExpression($expression),
			$this->attribute->expressionsFor($this->environment),
		);
	}

	/**
	 * Whether the cron is due in the given minute (evaluated in the minute's own timezone).
	 * With several expressions it is due when any of them is — and enqueued once.
	 */
	public function isDue(\DateTimeImmutable $minute): bool
	{
		foreach ($this->expressions() as $expression) {
			if ($expression->isDue($minute, $minute->getTimezone()->getName())) {
				return true;
			}
		}

		return false;
	}

	public function concurrencyMode(): string
	{
		return $this->attribute->concurrencyMode ?? ConcurrencyModeType::Exclusive;
	}

	public function maxQueueSize(): int
	{
		return $this->attribute->maxQueueSize ?? self::DEFAULT_MAX_QUEUE_SIZE;
	}
}
