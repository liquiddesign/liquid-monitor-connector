<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LiquidMonitorConnector\Worker\WorkerClient;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$claimPayload = [
	'jobs' => [
		[
			'jobId' => 10,
			'jobLogId' => 20,
			'cronCode' => 'import',
			'arguments' => ['userId' => 7],
			'timeout' => 120,
			'leaseSeconds' => 90,
			'concurrencyMode' => 'independent',
		],
	],
	'pendingJobs' => 3,
	'pollAfterSeconds' => 12,
];

$mock = new MockHandler([
	new Response(500),
	new Response(200, [], \json_encode($claimPayload, \JSON_THROW_ON_ERROR)),
]);

$http = new Client([
	'handler' => HandlerStack::create($mock),
	'http_errors' => true,
]);

$client = new WorkerClient('https://monitor.example/api_connector', 'API_KEY', $http);
$result = $client->claimJobs('worker-1', 2, 90);

Assert::count(1, $result->jobs);
Assert::same(10, $result->jobs[0]->jobId);
Assert::same(20, $result->jobs[0]->jobLogId);
Assert::same('import', $result->jobs[0]->cronCode);
Assert::same(['userId' => 7], $result->jobs[0]->arguments);
Assert::same(120, $result->jobs[0]->timeout);
Assert::same(90, $result->jobs[0]->leaseSeconds);
Assert::same('independent', $result->jobs[0]->concurrencyMode);
Assert::same(3, $result->pendingJobs);
Assert::same(12, $result->pollAfterSeconds);

// --- Exhausted retries throw the last transport error. ---
$retryMock = new MockHandler([
	new ConnectException('timeout', new Request('POST', 'claim-jobs')),
	new ConnectException('timeout', new Request('POST', 'claim-jobs')),
	new ConnectException('timeout', new Request('POST', 'claim-jobs')),
	new ConnectException('timeout', new Request('POST', 'claim-jobs')),
]);

$retryHttp = new Client([
	'handler' => HandlerStack::create($retryMock),
	'http_errors' => true,
]);

$retryClient = new WorkerClient('https://monitor.example/api_connector', 'API_KEY', $retryHttp);

Assert::exception(
	static fn () => $retryClient->claimJobs('worker-1', 1, 60),
	ConnectException::class,
);

echo "\nOK " . __FILE__ . "\n";
