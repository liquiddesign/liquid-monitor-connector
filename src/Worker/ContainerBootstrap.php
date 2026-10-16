<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Nette\DI\Container;

/**
 * Loads the host application's Nette container for a `monitor-worker` command.
 * Without `--bootstrap` it uses the universal bootstrap shipped with the package
 * (`App\Bootstrap`, or `NETTE_BOOTSTRAP_CLASS`), so `lqcron` can call PHP commands
 * without knowing the project's bootstrap file.
 */
final class ContainerBootstrap
{
	public static function resolvePath(string|null $bootstrap): string
	{
		$path = $bootstrap !== null && $bootstrap !== '' ? $bootstrap : MonitorWorkerBootstrap::vendorNetteBootstrapPath();

		if (!\is_file($path)) {
			throw new \RuntimeException("bootstrap file not found: {$path}");
		}

		return \realpath($path) ?: $path;
	}

	/**
	 * A service the command cannot run without. Older nette/di types getByType()
	 * as nullable; this fails with a readable message instead of a null.
	 * @template T of object
	 * @param class-string<T> $type
	 * @return T
	 */
	public static function service(Container $container, string $type): object
	{
		$service = $container->getByType($type, false);

		if (!$service instanceof $type) {
			throw new \RuntimeException(\sprintf('Service %s is not registered in the application container (is the liquidMonitorConnector extension enabled?).', $type));
		}

		return $service;
	}

	public static function load(string|null $bootstrap): Container
	{
		/** @var mixed $container */
		$container = require self::resolvePath($bootstrap);

		if (!$container instanceof Container) {
			throw new \RuntimeException('Bootstrap file must return a Nette\DI\Container instance.');
		}

		return $container;
	}
}
