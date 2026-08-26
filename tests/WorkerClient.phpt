<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\HandlerClosedException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\TransferException;
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

// --- A transport failure that is neither ConnectException nor RequestException is still retried.
// Guzzle 8 split the transport hierarchy: NetworkException, NetworkTimeoutException and
// HandlerClosedException extend TransferException directly, so a catch listing only
// ConnectException|RequestException dropped them onto the no-retry branch and a mid-transfer
// timeout on claim-jobs aborted the poll instead of backing off. TransferException is the common
// base in both majors; on Guzzle 7 no such class exists, so the scenario is Guzzle 8 only and the
// bare base stands in for it. Note TransferException gained a required $request in Guzzle 8, hence
// the version-aware construction. ---
$transportFailure = static function (): TransferException {
	$request = new Request('POST', 'claim-jobs');

	return \class_exists(NetworkTimeoutException::class)
		? new NetworkTimeoutException('read timeout', $request)
		: new TransferException('read timeout');
};

$retryingClient = static function (array $queue) use ($claimPayload): array {
	$mock = new MockHandler([...$queue, new Response(200, [], \json_encode($claimPayload, \JSON_THROW_ON_ERROR))]);
	$client = new WorkerClient(
		'https://monitor.example/api_connector',
		'API_KEY',
		new Client(['handler' => HandlerStack::create($mock), 'http_errors' => true]),
	);

	return [$client->claimJobs('worker-1', 2, 90), $mock];
};

[$transferResult, $transferMock] = $retryingClient([$transportFailure(), $transportFailure()]);
Assert::count(1, $transferResult->jobs);
Assert::same(10, $transferResult->jobs[0]->jobId);
Assert::count(0, $transferMock);

// A handler closed mid-flight is likewise transient (Guzzle 8 only).
if (\class_exists(HandlerClosedException::class)) {
	[$closedResult] = $retryingClient([new HandlerClosedException('handler closed', new Request('POST', 'claim-jobs'))]);
	Assert::count(1, $closedResult->jobs);
}

// Exhausting the retries still surfaces the transport error rather than swallowing it.
$exhaustedMock = new MockHandler([
	$transportFailure(),
	$transportFailure(),
	$transportFailure(),
	$transportFailure(),
]);

$exhaustedClient = new WorkerClient(
	'https://monitor.example/api_connector',
	'API_KEY',
	new Client(['handler' => HandlerStack::create($exhaustedMock), 'http_errors' => true]),
);

Assert::exception(
	static fn () => $exhaustedClient->claimJobs('worker-1', 1, 60),
	TransferException::class,
);

echo "\nOK " . __FILE__ . "\n";
