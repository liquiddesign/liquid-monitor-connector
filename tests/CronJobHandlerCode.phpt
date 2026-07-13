<?php

declare(strict_types=1);

use LiquidMonitorConnector\Worker\CronJobHandlerCode;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

final class UpdateCkpFloatingPricesHandler
{
}

Assert::same(
	'updateCkpFloatingPrices',
	CronJobHandlerCode::fromClassName(UpdateCkpFloatingPricesHandler::class),
);

try {
	CronJobHandlerCode::fromClassName(\stdClass::class);
	Assert::fail('Expected InvalidArgumentException');
} catch (\InvalidArgumentException $e) {
	Assert::contains('must end with "Handler"', $e->getMessage());
}

echo "\nOK " . __FILE__ . "\n";
