<?php

declare(strict_types=1);

use LiquidMonitorConnector\Orchestrator\RunLock;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$workerId = 'runlock-test-' . \getmypid();
$pidFile = \sys_get_temp_dir() . '/orchestrator-run-' . $workerId . '.pid';
@\unlink($pidFile);

$lock = new RunLock($workerId);

// First acquire wins and records our PID.
Assert::true($lock->acquire());
Assert::same((string) \getmypid(), \file_get_contents($pidFile));

// While the recorded PID is alive (it is ours), another acquire is refused.
Assert::false((new RunLock($workerId))->acquire());

// Release removes the PID file, next acquire succeeds again.
$lock->release();
Assert::false(\is_file($pidFile));
Assert::true($lock->acquire());
$lock->release();

// A stale PID (dead process) does not block.
\file_put_contents($pidFile, '99999999');
Assert::true($lock->acquire());
$lock->release();

// release() only removes the file when it holds our own PID.
\file_put_contents($pidFile, '99999999');
$lock->release();
Assert::true(\is_file($pidFile));
@\unlink($pidFile);

echo "\nOK " . __FILE__ . "\n";
