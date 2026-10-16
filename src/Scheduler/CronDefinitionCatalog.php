<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

use LiquidMonitorConnector\Worker\CronJobHandlerCode;
use LiquidMonitorConnector\Worker\PullCron;

/**
 * Every handler class with a `#[PullCron]` attribute, without instantiating the
 * handlers (the map is built at container compile time from service types).
 */
final class CronDefinitionCatalog
{
	/**
	 * @param array<string, class-string> $handlerClasses cron code => handler class
	 * @param list<string> $configFiles NEON files the container was compiled from
	 */
	public function __construct(
		private array $handlerClasses,
		private string|null $environment = null,
		private array $configFiles = [],
	) {
	}

	/**
	 * @return array<string, \LiquidMonitorConnector\Scheduler\CronDefinition> keyed by cron code, sorted
	 */
	public function all(string|null $environment = null): array
	{
		$definitions = [];

		foreach ($this->handlerClasses as $code => $class) {
			$attribute = self::attributeOf($class);

			if ($attribute === null) {
				continue;
			}

			$definitions[$code] = new CronDefinition($code, $class, $attribute, $environment ?? $this->environment);
		}

		\ksort($definitions);

		return $definitions;
	}

	public function get(string $code): CronDefinition|null
	{
		return $this->all()[$code] ?? null;
	}

	public function has(string $code): bool
	{
		return isset($this->handlerClasses[$code]) && self::attributeOf($this->handlerClasses[$code]) !== null;
	}

	/**
	 * @param class-string $class
	 */
	public function codeOf(string $class): string|null
	{
		$code = \array_search($class, $this->handlerClasses, true);

		if (\is_string($code)) {
			return $code;
		}

		try {
			return CronJobHandlerCode::fromClassName($class);
		} catch (\InvalidArgumentException) {
			return null;
		}
	}

	public function environment(): string|null
	{
		return $this->environment;
	}

	/**
	 * @return list<string>
	 */
	public function configFiles(): array
	{
		return $this->configFiles;
	}

	/**
	 * @param class-string $class
	 */
	public static function attributeOf(string $class): PullCron|null
	{
		if (!\class_exists($class)) {
			return null;
		}

		$attributes = (new \ReflectionClass($class))->getAttributes(PullCron::class);

		return $attributes === [] ? null : $attributes[0]->newInstance();
	}
}
