<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Strings;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Pull-model worker loop: claim jobs, spawn child processes, heartbeat, report finish/fail.
 */
final class WorkerRunLoop
{
	private bool $claimedInOnceMode = false;

	/**
	 * @param \Closure(\LiquidMonitorConnector\Worker\ClaimedJob): \Symfony\Component\Process\Process $spawnChild
	 */
	public function __construct(
		private readonly WorkerClientContract $client,
		private readonly string $workerId,
		private readonly int $maxParallel,
		private readonly int $pollInterval,
		private readonly int $maxRuntime,
		private readonly int $leaseSeconds,
		private readonly bool $once,
		private readonly \Closure $spawnChild,
		private readonly ?OutputInterface $output = null,
	) {
	}

	public function run(): int
	{
		$startedAt = \time();
		$deadline = $startedAt + $this->maxRuntime;
		/** @var array<int, array{job: \LiquidMonitorConnector\Worker\ClaimedJob, process: \Symfony\Component\Process\Process, lastHeartbeatAt: int}> $running */
		$running = [];
		$pollAfterSeconds = $this->pollInterval;

		while (true) {
			$this->collectExited($running);

			$freeCapacity = $this->maxParallel - \count($running);

			if ($freeCapacity > 0 && (!$this->once || !$this->claimedInOnceMode)) {
				try {
					$claim = $this->client->claimJobs($this->workerId, $freeCapacity, $this->leaseSeconds);
					$this->claimedInOnceMode = true;
					$pollAfterSeconds = $claim->pollAfterSeconds > 0 ? $claim->pollAfterSeconds : $this->pollInterval;

					foreach ($claim->jobs as $job) {
						if (\count($running) >= $this->maxParallel) {
							break;
						}

						$process = ($this->spawnChild)($job);
						$process->start();
						$running[$job->jobId] = [
							'job' => $job,
							'process' => $process,
							'lastHeartbeatAt' => \time(),
						];
					}
				} catch (\Throwable $e) {
					$this->claimedInOnceMode = true;
					$this->writeln('<error>Claim jobs failed: ' . $e->getMessage() . '</error>');

					if ($this->once && $running === []) {
						return 1;
					}
				}
			}

			$this->collectExited($running);
			$this->heartbeatRunning($running);

			if ($this->once) {
				if ($this->claimedInOnceMode && $running === []) {
					return 0;
				}
			} elseif (\time() >= $deadline) {
				$this->waitForRunning($running);

				return 0;
			}

			if ($running === []) {
				if ($this->once) {
					return 0;
				}

				\sleep($pollAfterSeconds);
			} else {
				\sleep(1);
			}
		}
	}

	/**
	 * @param array<int, array{job: \LiquidMonitorConnector\Worker\ClaimedJob, process: \Symfony\Component\Process\Process, lastHeartbeatAt: int}> $running
	 */
	private function collectExited(array &$running): void
	{
		foreach ($running as $jobId => $entry) {
			$process = $entry['process'];

			if ($process->isRunning()) {
				continue;
			}

			$job = $entry['job'];

			try {
				if ($process->isSuccessful()) {
					$this->client->finishJob($jobId, $this->parseChildResult($process));
				} else {
					$error = Strings::trim($process->getErrorOutput());

					if ($error === '') {
						$error = Strings::trim($process->getOutput());
					}

					if ($error === '') {
						$error = \sprintf('Child process exited with code %d', $process->getExitCode() ?? 1);
					}

					$this->client->failJob($jobId, ['error' => $error, 'cronCode' => $job->cronCode]);
				}
			} catch (\Throwable $e) {
				$this->writeln('<error>Reporting job ' . $jobId . ' failed: ' . $e->getMessage() . '</error>');
			}

			unset($running[$jobId]);
		}
	}

	/**
	 * @param array<int, array{job: \LiquidMonitorConnector\Worker\ClaimedJob, process: \Symfony\Component\Process\Process, lastHeartbeatAt: int}> $running
	 */
	private function heartbeatRunning(array &$running): void
	{
		$interval = (int) \max(1, (int) \floor($this->leaseSeconds / 2));
		$now = \time();

		foreach ($running as $jobId => $entry) {
			if ($now - $entry['lastHeartbeatAt'] < $interval) {
				continue;
			}

			try {
				$this->client->heartbeatJob($jobId);
				$running[$jobId]['lastHeartbeatAt'] = $now;
			} catch (\Throwable $e) {
				$this->writeln('<comment>Heartbeat for job ' . $jobId . ' failed: ' . $e->getMessage() . '</comment>');
			}
		}
	}

	/**
	 * @param array<int, array{job: \LiquidMonitorConnector\Worker\ClaimedJob, process: \Symfony\Component\Process\Process, lastHeartbeatAt: int}> $running
	 */
	private function waitForRunning(array &$running): void
	{
		while ($running !== []) {
			foreach ($running as $entry) {
				if ($entry['process']->isRunning()) {
					$entry['process']->wait();
				}
			}

			$this->collectExited($running);
			$this->heartbeatRunning($running);

			if ($running === []) {
				continue;
			}

			\sleep(1);
		}
	}

	/**
	 * @return array<mixed>|null
	 */
	private function parseChildResult(Process $process): ?array
	{
		$output = Strings::trim($process->getOutput());

		if ($output === '') {
			return null;
		}

		try {
			$decoded = Json::decode($output, forceArrays: true);

			return \is_array($decoded) ? $decoded : null;
		} catch (JsonException) {
			return ['output' => $output];
		}
	}

	private function writeln(string $message): void
	{
		$this->output?->writeln($message);
	}
}
