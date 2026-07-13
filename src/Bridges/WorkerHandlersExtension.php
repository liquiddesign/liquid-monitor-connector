<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Bridges;

use LiquidMonitorConnector\Worker\CronJobHandlerRegistry;
use LiquidMonitorConnector\Worker\WorkerHandlerDiscovery;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * Registers pull-model cron handlers for `bin/monitor-worker`.
 *
 * Host application:
 *
 *   extensions:
 *       liquidMonitorWorkerHandlers: LiquidMonitorConnector\Bridges\WorkerHandlersExtension
 *
 *   liquidMonitorWorkerHandlers:
 *       handlers: auto
 *
 * Alternatively nest under the main connector extension:
 *
 *   liquidMonitorConnector:
 *       url: https://monitor.example/api/connector
 *       apiKey: PROJECT_KEY
 *       workerHandlers:
 *           import: @App\Cron\ImportHandler
 */
class WorkerHandlersExtension extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'handlers' => Expect::anyOf(
				Expect::string('auto'),
				Expect::arrayOf(Expect::string()),
			)->default([]),
		]);
	}

	public function loadConfiguration(): void
	{
		// Schema-only extension; handler services are wired in beforeCompile().
	}

	public function beforeCompile(): void
	{
		if (!\class_exists(CronJobHandlerRegistry::class)) {
			return;
		}

		/** @var \stdClass $config */
		$config = $this->getConfig();
		$handlers = WorkerHandlerDiscovery::resolve($this->getContainerBuilder(), $config->handlers);

		if ($handlers === []) {
			return;
		}

		$builder = $this->getContainerBuilder();

		$builder->addDefinition($this->prefix('handlerRegistry'))
			->setType(CronJobHandlerRegistry::class)
			->setFactory(CronJobHandlerRegistry::class, ['handlers' => $handlers]);

		$builder->addAlias(CronJobHandlerRegistry::class, $this->prefix('handlerRegistry'));
	}
}
