<?php

use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

use LiquidMonitorConnector\Worker\MonitorWorkerBootstrap;

$tempRoot = \sys_get_temp_dir() . '/monitor-worker-bootstrap-' . \getmypid();
\mkdir($tempRoot . '/vendor/liquiddesign/liquid-monitor-connector', 0777, true);

\file_put_contents($tempRoot . '/composer.json', \json_encode([
	'name' => 'acme/host-app',
	'require' => ['liquiddesign/liquid-monitor-connector' => '^3.0'],
], \JSON_THROW_ON_ERROR));

\file_put_contents($tempRoot . '/vendor/liquiddesign/liquid-monitor-connector/composer.json', \json_encode([
	'name' => MonitorWorkerBootstrap::PACKAGE_NAME,
], \JSON_THROW_ON_ERROR));

Assert::same($tempRoot, MonitorWorkerBootstrap::resolveProjectRoot($tempRoot));
Assert::same($tempRoot, MonitorWorkerBootstrap::resolveProjectRoot($tempRoot . '/vendor/liquiddesign/liquid-monitor-connector'));

Assert::same('App\\Bootstrap', MonitorWorkerBootstrap::bootstrapClass());

\putenv(MonitorWorkerBootstrap::ENV_BOOTSTRAP_CLASS . '=Custom\\Bootstrap');
Assert::same('Custom\\Bootstrap', MonitorWorkerBootstrap::bootstrapClass());
\putenv(MonitorWorkerBootstrap::ENV_BOOTSTRAP_CLASS);

$vendorBootstrap = MonitorWorkerBootstrap::vendorNetteBootstrapPath();
Assert::true(\is_file($vendorBootstrap));
Assert::true(MonitorWorkerBootstrap::isVendorNetteBootstrap($vendorBootstrap));

\exec('rm -rf ' . \escapeshellarg($tempRoot));
