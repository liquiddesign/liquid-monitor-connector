<?php

declare(strict_types=1);

use LiquidMonitorConnector\Bridges\LiquidMonitorConnectorDI;
use LiquidMonitorConnector\Worker\CronJobHandler;
use LiquidMonitorConnector\Worker\CronJobHandlerRegistry;
use LiquidMonitorConnector\Worker\WorkerHandlerDiscovery;
use Nette\DI\Compiler;
use Nette\DI\ContainerBuilder;
use Nette\Schema\Processor;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

final class ImportHandler implements CronJobHandler
{
	public function execute(?array $arguments): void
	{
	}
}

// --- WorkerHandlerDiscovery: auto from DI ---
$builder = new ContainerBuilder();
$builder->addDefinition('autoImportHandler')->setType(ImportHandler::class);

$handlers = WorkerHandlerDiscovery::resolve($builder, 'auto');
Assert::same(['import' => '@autoImportHandler'], $handlers);

// --- LiquidMonitorConnectorDI: workerHandlers auto ---
$connectorExtension = new LiquidMonitorConnectorDI();
$connectorExtension->setCompiler(new Compiler(), 'liquidMonitorConnector');
$connectorExtension->setConfig((new Processor())->process($connectorExtension->getConfigSchema(), [
	'url' => 'https://monitor.example/api_connector',
	'apiKey' => 'KEY',
	'workerHandlers' => 'auto',
]));
$connectorExtension->loadConfiguration();

$connectorBuilder = $connectorExtension->getContainerBuilder();
$connectorBuilder->addDefinition('cleanupHandler')->setType(ImportHandler::class);

$connectorExtension->beforeCompile();

$nestedRegistry = $connectorBuilder->getDefinition('liquidMonitorConnector.workerHandlerRegistry');
Assert::same(CronJobHandlerRegistry::class, $nestedRegistry->getType());
Assert::same(['import' => '@cleanupHandler'], $nestedRegistry->getFactory()->arguments['handlers']);

echo "\nOK " . __FILE__ . "\n";
