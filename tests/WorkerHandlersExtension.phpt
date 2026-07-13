<?php

declare(strict_types=1);

use LiquidMonitorConnector\Bridges\LiquidMonitorConnectorDI;
use LiquidMonitorConnector\Bridges\WorkerHandlersExtension;
use LiquidMonitorConnector\Worker\CronJobHandler;
use LiquidMonitorConnector\Worker\CronJobHandlerRegistry;
use Nette\DI\Compiler;
use Nette\Schema\Processor;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$method = static fn ($setup): ?string => \is_array($setup->getEntity()) ? ($setup->getEntity()[1] ?? null) : $setup->getEntity();

final class TestImportHandler implements CronJobHandler
{
	public function execute(?array $arguments): void
	{
	}
}

// --- Standalone WorkerHandlersExtension registers handler registry. ---
$workerExtension = new WorkerHandlersExtension();
$workerExtension->setCompiler(new Compiler(), 'liquidMonitorWorkerHandlers');
$workerExtension->setConfig((new Processor())->process($workerExtension->getConfigSchema(), [
	'handlers' => [
		'import' => '@importHandler',
	],
]));
$workerExtension->loadConfiguration();

$builder = $workerExtension->getContainerBuilder();
$builder->addDefinition('importHandler')->setType(TestImportHandler::class);

$workerExtension->beforeCompile();

$registryDef = $builder->getDefinition('liquidMonitorWorkerHandlers.handlerRegistry');
Assert::same(CronJobHandlerRegistry::class, $registryDef->getType());
Assert::same(['import' => '@importHandler'], $registryDef->getFactory()->arguments['handlers']);
Assert::true($builder->getAliases()[CronJobHandlerRegistry::class] === 'liquidMonitorWorkerHandlers.handlerRegistry');

// --- Nested workerHandlers on liquidMonitorConnector extension. ---
$connectorExtension = new LiquidMonitorConnectorDI();
$connectorExtension->setCompiler(new Compiler(), 'liquidMonitorConnector');
$connectorExtension->setConfig((new Processor())->process($connectorExtension->getConfigSchema(), [
	'url' => 'https://monitor.example/api_connector',
	'apiKey' => 'KEY',
	'workerHandlers' => [
		'cleanup' => '@cleanupHandler',
	],
]));
$connectorExtension->loadConfiguration();

$connectorBuilder = $connectorExtension->getContainerBuilder();
$connectorBuilder->addDefinition('cleanupHandler')->setType(TestImportHandler::class);

$connectorExtension->beforeCompile();

$nestedRegistry = $connectorBuilder->getDefinition('liquidMonitorConnector.workerHandlerRegistry');
Assert::same(CronJobHandlerRegistry::class, $nestedRegistry->getType());
Assert::same(['cleanup' => '@cleanupHandler'], $nestedRegistry->getFactory()->arguments['handlers']);

echo "\nOK " . __FILE__ . "\n";
