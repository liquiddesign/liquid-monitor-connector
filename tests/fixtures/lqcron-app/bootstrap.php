<?php

declare(strict_types=1);

// Bootstrap of the end-to-end fixture application: a real Nette container with
// the connector extension, compiled from config.neon (+ the generated local.neon).

use LiquidMonitorConnector\Bridges\LiquidMonitorConnectorDI;
use Nette\DI\Compiler;
use Nette\DI\ContainerLoader;

$slot = \getenv('LQCRON_E2E_SLOT') ?: \getcwd();
require \getenv('LQCRON_E2E_AUTOLOAD');
require __DIR__ . '/Handlers.php';

$loader = new ContainerLoader($slot . '/temp/cache', true);
$class = $loader->load(static function (Compiler $compiler) use ($slot): void {
	$compiler->addExtension('liquidMonitorConnector', new LiquidMonitorConnectorDI());
	$compiler->loadConfig(__DIR__ . '/config.neon');
	$compiler->loadConfig($slot . '/local.neon');
}, 'e2e');

return new $class();
