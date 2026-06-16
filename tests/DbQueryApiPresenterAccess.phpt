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

echo "\nOK " . __FILE__ . "\n";
