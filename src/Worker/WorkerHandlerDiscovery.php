<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Nette\DI\ContainerBuilder;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\InvalidConfigurationException;

/**
 * Builds cronCode → @service map from explicit NEON config and/or DI auto-discovery.
 */
final class WorkerHandlerDiscovery
{
	/**
	 * @param array<string, string>|'auto' $configured Manual map or {@code auto} to discover all {@see CronJobHandler} services.
	 * @return array<string, string>
	 */
	public static function resolve(ContainerBuilder $builder, array|string $configured): array
	{
		$handlers = \is_array($configured) ? $configured : [];

		if ($configured !== 'auto' && $handlers === []) {
			return [];
		}

		if ($configured === 'auto') {
			$handlers = self::mergeDiscovered($builder, $handlers);
		}

		return $handlers;
	}

	/**
	 * @param array<string, string> $handlers
	 * @return array<string, string>
	 */
	private static function mergeDiscovered(ContainerBuilder $builder, array $handlers): array
	{
		foreach ($builder->findByType(CronJobHandler::class) as $serviceName => $definition) {
			if (!$definition instanceof ServiceDefinition) {
				continue;
			}

			$type = $definition->getType();

			if ($type === null || !\class_exists($type)) {
				continue;
			}

			$cronCode = CronJobHandlerCode::fromClassName($type);

			if (isset($handlers[$cronCode])) {
				throw new InvalidConfigurationException(\sprintf(
					'Duplicate monitor cron handler for code "%s": "%s" and "%s".',
					$cronCode,
					$handlers[$cronCode],
					$type,
				));
			}

			$handlers[$cronCode] = '@' . $serviceName;
		}

		return $handlers;
	}
}
