<?php

declare(strict_types=1);

use LiquidMonitorConnector\Worker\ClaimedJob;
use LiquidMonitorConnector\Worker\ClaimJobsResult;
use LiquidMonitorConnector\Worker\WorkerClientContract;
use LiquidMonitorConnector\Worker\WorkerRunLoop;
use Symfony\Component\Process\Process;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// WorkerRunLoop declares `$spawnChild` as returning a Symfony Process and parseChildResult()
// type-hints it natively, so this double has to BE a Process — a look-alike makes finishJob()
// throw a TypeError that the loop's broad `catch (\Throwable)` around reporting swallows into
// writeln(), silently losing the finish report. Extending Process honours that contract while
// keeping the test deterministic (no real subprocess).
//
// It models a child that has already exited by the time the loop looks at it, so isRunning() is
// always false — which is also what a real Process reports before start(). Do NOT make it true
// before start(): Process::__construct() calls setInput(), which asks isRunning() and throws
// "Input cannot be set while the process is running" on an overridden true.
final class FakeChildProcess extends Process
{
	public function __construct(
		private readonly int $fakeExitCode,
		private readonly string $fakeStdout = '',
		private readonly string $fakeStderr = '',
	) {
		parent::__construct(['true']);
	}

	public function start(?callable $callback = null, array $env = []): void
	{
	}

	public function isRunning(): bool
	{
		return false;
	}

	public function isSuccessful(): bool
	{
		return $this->fakeExitCode === 0;
	}

	public function getExitCode(): ?int
	{
		return $this->fakeExitCode;
	}

	public function getOutput(): string
	{
		return $this->fakeStdout;
	}

	public function getErrorOutput(): string
	{
		return $this->fakeStderr;
	}

	public function wait(?callable $callback = null): int
	{
		return $this->fakeExitCode;
	}

	public function checkTimeout(): void
	{
	}
}

final class FakeWorkerClient implements WorkerClientContract
{
	/** @var list<array{workerId: string, maxJobs: int, leaseSeconds: int}> */
	public array $claims = [];

	/** @var list<int> */
	public array $heartbeats = [];

	/** @var list<array{jobId: int, data: array<mixed>|null}> */
	public array $finished = [];

	/** @var list<array{jobId: int, data: array<mixed>|null}> */
	public array $failed = [];

	private int $claimCalls = 0;

	/**
	 * @param list<ClaimedJob> $firstBatch
	 * @param list<ClaimedJob> $secondBatch
	 * @param int|null $maxClaims Stop returning jobs after this many claim calls.
	 */
	public function __construct(
		private readonly array $firstBatch,
		private readonly array $secondBatch = [],
		private readonly ?int $maxClaims = null,
	) {
	}

	public function claimJobs(string $workerId, int $maxJobs, int $leaseSeconds): ClaimJobsResult
	{
		if ($this->maxClaims !== null && \count($this->claims) >= $this->maxClaims) {
			return new ClaimJobsResult([], 0, 1);
		}

		$this->claims[] = [
			'workerId' => $workerId,
			'maxJobs' => $maxJobs,
			'leaseSeconds' => $leaseSeconds,
		];

		$this->claimCalls++;
		$jobs = $this->claimCalls === 1 ? $this->firstBatch : $this->secondBatch;

		return new ClaimJobsResult($jobs, 0, 1);
	}

	public function heartbeatJob(int $jobId): void
	{
		$this->heartbeats[] = $jobId;
	}

	public function finishJob(int $jobId, ?array $data = null): void
	{
		$this->finished[] = ['jobId' => $jobId, 'data' => $data];
	}

	public function failJob(int $jobId, ?array $data = null): void
	{
		$this->failed[] = ['jobId' => $jobId, 'data' => $data];
	}

	public function progressJob(int $jobId, ?array $data = null): void
	{
	}
}

$successJob = new ClaimedJob(1, 11, 'ok', null, 5, 60, 'independent');
$failJob = new ClaimedJob(2, 22, 'fail', null, 5, 60, 'independent');

$client = new FakeWorkerClient([$successJob, $failJob]);

$loop = new WorkerRunLoop(
	client: $client,
	workerId: 'test-worker#1',
	maxParallel: 2,
	pollInterval: 1,
	maxRuntime: 5,
	leaseSeconds: 4,
	once: true,
	spawnChild: static function (ClaimedJob $job): Process {
		if ($job->jobId === 1) {
			return new FakeChildProcess(0, '{"status":"ok"}');
		}

		return new FakeChildProcess(3, '', 'boom');
	},
);

Assert::same(0, $loop->run());

Assert::count(1, $client->claims);
Assert::same(2, $client->claims[0]['maxJobs']);
Assert::count(1, $client->finished);
Assert::same(1, $client->finished[0]['jobId']);
Assert::same('ok', $client->finished[0]['data']['status'] ?? null);
Assert::count(1, $client->failed);
Assert::same(2, $client->failed[0]['jobId']);
Assert::true(\str_contains((string) ($client->failed[0]['data']['error'] ?? ''), 'boom'));

// --- Freed capacity triggers another claim in non-once mode. ---
$capacityClient = new FakeWorkerClient(
	firstBatch: [new ClaimedJob(10, 110, 'first', null, 5, 60, 'independent')],
	secondBatch: [new ClaimedJob(11, 111, 'second', null, 5, 60, 'independent')],
	maxClaims: 2,
);

$capacityLoop = new WorkerRunLoop(
	client: $capacityClient,
	workerId: 'capacity-worker#1',
	maxParallel: 1,
	pollInterval: 1,
	maxRuntime: 3,
	leaseSeconds: 60,
	once: false,
	spawnChild: static fn (ClaimedJob $job): Process => new FakeChildProcess(0, \json_encode(['jobId' => $job->jobId], \JSON_THROW_ON_ERROR)),
);

Assert::same(0, $capacityLoop->run());
Assert::count(2, $capacityClient->claims);
Assert::count(2, $capacityClient->finished);

echo "\nOK " . __FILE__ . "\n";
