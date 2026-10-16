<?php

declare(strict_types=1);

use LiquidMonitorConnector\HealthCheck\HealthCheckData;
use LiquidMonitorConnector\HealthCheck\HealthCheckResponse;
use LiquidMonitorConnector\HealthCheck\HealthCheckStatusEnum;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// 4.0: HealthCheckStatusEnum je kvůli PHP 8.0 třída s int konstantami místo `enum: int`.
// Hodnoty i JSON health checku ('status' => 'OK' …) musí zůstat stejné jako u enumu ve 3.x.
Assert::same(-1, HealthCheckStatusEnum::INFO);
Assert::same(0, HealthCheckStatusEnum::OK);
Assert::same(1, HealthCheckStatusEnum::WARNING);
Assert::same(2, HealthCheckStatusEnum::ERROR);
Assert::same(3, HealthCheckStatusEnum::CRITICAL);
Assert::same(['INFO' => -1, 'OK' => 0, 'WARNING' => 1, 'ERROR' => 2, 'CRITICAL' => 3], HealthCheckStatusEnum::cases());

Assert::same(HealthCheckStatusEnum::ERROR, HealthCheckStatusEnum::from(2));
Assert::null(HealthCheckStatusEnum::tryFrom(7));
Assert::same('WARNING', HealthCheckStatusEnum::name(HealthCheckStatusEnum::WARNING));
Assert::exception(static fn () => HealthCheckStatusEnum::from(7), ValueError::class);
Assert::exception(static fn () => new HealthCheckData('disk', 1, 7), ValueError::class);

// Výchozí stav dat je OK, toArray() vrací název stavu.
Assert::same(
	['name' => 'queue', 'value' => 5, 'status' => 'OK', 'description' => null],
	(new HealthCheckData('queue', 5))->toArray(),
);
Assert::same(HealthCheckStatusEnum::OK, (new HealthCheckData('queue', 5))->getStatus());

// Odpověď převezme nejhorší stav ze svých dat; INFO ji nezhorší.
$response = HealthCheckResponse::ok()
	->addData(new HealthCheckData('info', 'x', HealthCheckStatusEnum::INFO))
	->addData(new HealthCheckData('disk', 91, HealthCheckStatusEnum::WARNING, 'Disk 91 %'))
	->addData(new HealthCheckData('db', 'down', HealthCheckStatusEnum::CRITICAL))
	->addData(new HealthCheckData('queue', 5, HealthCheckStatusEnum::ERROR));

/** @var array{status: string, statusTs: string, data: list<array<string, mixed>>} $payload */
$payload = $response->getResponse()->getPayload();
Assert::same('CRITICAL', $payload['status']);
Assert::same(['INFO', 'WARNING', 'CRITICAL', 'ERROR'], array_column($payload['data'], 'status'));
Assert::same('Disk 91 %', $payload['data'][1]['description']);

$plain = HealthCheckResponse::warning()->getResponse()->getPayload();
Assert::same('WARNING', $plain['status']);
Assert::false(isset($plain['data']));

$set = HealthCheckResponse::ok();
$set->setStatus(HealthCheckStatusEnum::ERROR);
Assert::same('ERROR', $set->getResponse()->getPayload()['status']);
Assert::exception(static fn () => $set->setStatus(9), ValueError::class);
