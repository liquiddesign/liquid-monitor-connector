<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

/**
 * Locates the host Nette project and builds a DI container for monitor-worker.
 */
final class MonitorWorkerBootstrap
{
	public const PACKAGE_NAME = 'liquiddesign/liquid-monitor-connector';

	public const ENV_BOOTSTRAP_CLASS = 'NETTE_BOOTSTRAP_CLASS';

	public const DEFAULT_BOOTSTRAP_CLASS = 'App\\Bootstrap';

	/**
	 * Walk upward from a path until the host project's composer.json is found.
	 */
	public static function resolveProjectRoot(string $startPath): string
	{
		$dir = \realpath($startPath);

		if ($dir === false) {
			throw new \RuntimeException("monitor-worker: invalid path {$startPath}");
		}

		while (true) {
			$composerFile = $dir . '/composer.json';

			if (\is_file($composerFile)) {
				/** @var array<string, mixed>|null $composer */
				$composer = \json_decode((string) \file_get_contents($composerFile), true);

				if (\is_array($composer) && ($composer['name'] ?? '') !== self::PACKAGE_NAME) {
					return $dir;
				}
			}

			$parent = \dirname($dir);

			if ($parent === $dir) {
				break;
			}

			$dir = $parent;
		}

		throw new \RuntimeException('monitor-worker: could not locate host project root (composer.json)');
	}

	public static function bootstrapClass(): string
	{
		$fromEnv = self::envString(self::ENV_BOOTSTRAP_CLASS);

		return $fromEnv ?? self::DEFAULT_BOOTSTRAP_CLASS;
	}

	/**
	 * Universal Nette bootstrap shipped with the connector package.
	 */
	public static function vendorNetteBootstrapPath(): string
	{
		return \dirname(__DIR__, 2) . '/bin/monitor-worker-nette-bootstrap.php';
	}

	public static function isVendorNetteBootstrap(string $bootstrapPath): bool
	{
		$normalized = \str_replace('\\', '/', \realpath($bootstrapPath) ?: $bootstrapPath);
		$vendorBootstrap = \str_replace('\\', '/', self::vendorNetteBootstrapPath());

		return $normalized === $vendorBootstrap;
	}

	private static function envString(string $name): ?string
	{
		$value = \getenv($name);

		if (\is_string($value) && $value !== '') {
			return $value;
		}

		return null;
	}
}
