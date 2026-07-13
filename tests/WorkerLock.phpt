<?php

declare(strict_types=1);

use LiquidMonitorConnector\Worker\WorkerLock;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$workerId = 'workerlock-test-' . \getmypid();
$pidFile = \sys_get_temp_dir() . '/monitor-worker-' . $workerId . '.pid';
@\unlink($pidFile);

$lock = new WorkerLock($workerId);

Assert::true($lock->acquire());
Assert::same((string) \getmypid(), \file_get_contents($pidFile));

Assert::false((new WorkerLock($workerId))->acquire());

$lock->release();
Assert::false(\is_file($pidFile));
Assert::true($lock->acquire());
$lock->release();

\file_put_contents($pidFile, '99999999');
Assert::true($lock->acquire());
$lock->release();

\file_put_contents($pidFile, '99999999');
$lock->release();
Assert::true(\is_file($pidFile));
@\unlink($pidFile);

echo "\nOK " . __FILE__ . "\n";
