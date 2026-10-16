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
