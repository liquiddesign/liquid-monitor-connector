<?php

declare(strict_types=1);

use LiquidMonitorConnector\Worker\MonitorWorkerBootstrap;
use Nette\DI\Container;

$projectRoot = MonitorWorkerBootstrap::resolveProjectRoot(__DIR__);

require $projectRoot . '/vendor/autoload.php';

$bootstrapClass = MonitorWorkerBootstrap::bootstrapClass();

if (!\class_exists($bootstrapClass)) {
	throw new \RuntimeException("monitor-worker: bootstrap class {$bootstrapClass} not found");
}

if (!\is_callable([$bootstrapClass, 'boot'])) {
	throw new \RuntimeException("monitor-worker: {$bootstrapClass}::boot() is not callable");
}

/** @var mixed $booted */
$booted = $bootstrapClass::boot();

if ($booted instanceof Container) {
	return $booted;
}

if (!\is_object($booted) || !\method_exists($booted, 'createContainer')) {
	throw new \RuntimeException("monitor-worker: {$bootstrapClass}::boot() must return Configurator or Container");
}

/** @var \Nette\DI\Container $container */
$container = $booted->createContainer();

return $container;
