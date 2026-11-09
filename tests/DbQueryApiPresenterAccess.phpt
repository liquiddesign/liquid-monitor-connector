<?php

declare(strict_types=1);

use LiquidMonitorConnector\DbQuery\DbQueryApiPresenter;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// Trusted-IP gate mirrors the host's Tracy debug mode (Debugger::$productionMode).

// Debug mode for this client (trusted IP) → served.
Assert::true(DbQueryApiPresenter::isTrustedDebugMode(false));

// Production mode → blocked.
Assert::false(DbQueryApiPresenter::isTrustedDebugMode(true));

// Undetermined (Tracy Detect default, e.g. before bootstrap) → fail closed.
Assert::false(DbQueryApiPresenter::isTrustedDebugMode(null));

// Connection host allow-list — loopback by default (the monitor's proxy mode sends 127.0.0.1).
$config = new LiquidMonitorConnector\DbQuery\DbQueryConfig();
Assert::true($config->isHostAllowed('127.0.0.1'));
Assert::true($config->isHostAllowed('LOCALHOST'));
Assert::true($config->isHostAllowed('[::1]'));
Assert::false($config->isHostAllowed('10.0.0.5'));
Assert::false($config->isHostAllowed('db.internal'));
Assert::false($config->isHostAllowed('127.0.0.1.evil.example'));

$custom = new LiquidMonitorConnector\DbQuery\DbQueryConfig(null, ['db']);
Assert::true($custom->isHostAllowed('db'));
Assert::false($custom->isHostAllowed('127.0.0.1'));

echo "\nOK " . __FILE__ . "\n";
