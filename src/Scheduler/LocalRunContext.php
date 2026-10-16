<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

// phpcs:disable Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- size limits are in bytes (strlen), not characters

use LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException;
use LiquidMonitorConnector\Tasks\ExceptionToJsonArray;

/**
 * The PHP child of a local run (`monitor-worker execute --local`), seen from the
 * inside: it moves the run to `running`, records progress, and writes the end
 * marker `runs/<uuid>.exit.json` (docs/v4-contract.md § 2.2, § 3). The final
 * state is decided by `lqcron` from the marker, never by PHP.
 *
 * `Cron::progressJob()` / `finishJob()` / `failJob()` called by a handler land
 * here while a context is active ({@see current()}).
 */
final class LocalRunContext
{
	public const ENV_RUN_ID = 'LQCRON_RUN_ID';
	public const ENV_STATE_DIR = 'LQCRON_STATE_DIR';
	public const ENV_TIMEOUT = 'LQCRON_TIMEOUT';
	public const ENV_LOG_FILE = 'LQCRON_LOG_FILE';

	/** Progress events are coalesced: at most one per run per this many ms, the last value wins (§ 7.4). */
	public const PROGRESS_INTERVAL_MS = 10000;

	public const RESULT_MAX_BYTES = 262144;
	public const RESULT_HEAD_BYTES = 4096;
	public const PROGRESS_MAX_BYTES = 16384;

	/** Cap of an error message and of its trace each (§ 8.1: no event over the size limit). */
	public const ERROR_MAX_BYTES = 65536;

	/** @var array<string, mixed>|null */
	private array|null $run = null;

	private mixed $result = null;

	private bool $explicitFail = false;

	private bool $markerWritten = false;

	private int $lastProgressAtMs = 0;

	private bool $hasPendingProgress = false;

	private mixed $pendingProgress = null;

	private static self|null $current = null;

	public function __construct(private string $stateDir, private string $runUuid)
	{
	}

	public function runUuid(): string
	{
		return $this->runUuid;
	}

	public function markerPath(): string
	{
		return self::runFile($this->stateDir, $this->runUuid, 'exit.json');
	}

	/**
	 * CAS `starting → running` with this process's pid, plus `run.started`, in one
	 * transaction. Returns false when the run is not ours to start (cancelled or
	 * reclaimed meanwhile) — the handler must not run then.
	 * @throws \LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException
	 */
	public function markRunning(string $appPath): bool
	{
		$connection = SchedulerDatabase::openForWrite($this->stateDir);

		try {
			return $connection->transaction(function (SqliteConnection $connection) use ($appPath): bool {
				$now = Clock::nowMs();
				$pid = \getmypid() ?: 0;

				$changed = $connection->execute(
					"UPDATE runs SET state = 'running', pid = ?, pid_start = ?, started_at = ?, app_path = ? WHERE uuid = ? AND state = 'starting'",
					[$pid, ProcessInfo::startTime($pid), $now, $appPath, $this->runUuid],
				);

				if ($changed !== 1) {
					return false;
				}

				$run = $connection->fetchOne('SELECT * FROM runs WHERE uuid = ?', [$this->runUuid]);

				if ($run === null) {
					return false;
				}

				$this->run = $run;

				EventOutbox::insert($connection, 'run.started', $this->commonPayload() + [
					'started_at' => Clock::iso($now),
					'pid' => $pid,
					'hostname' => \gethostname() ?: null,
				], $this->runUuid, $now);

				return true;
			});
		} finally {
			$connection->close();
		}
	}

	public function progress(mixed $data): void
	{
		$now = Clock::nowMs();

		if ($now - $this->lastProgressAtMs < self::PROGRESS_INTERVAL_MS) {
			$this->pendingProgress = $data;
			$this->hasPendingProgress = true;

			return;
		}

		$this->writeProgress($data, $now);
	}

	public function flushProgress(): void
	{
		if ($this->hasPendingProgress) {
			$this->writeProgress($this->pendingProgress, Clock::nowMs());
		}
	}

	public function finish(mixed $data): void
	{
		$this->result = $data;
	}

	/**
	 * Explicit `Cron::failJob()` from the handler: the run fails even with exit 0.
	 * @param mixed $data
	 */
	public function fail(mixed $data): void
	{
		$this->result = $data;
		$this->explicitFail = true;
	}

	public function isMarkerWritten(): bool
	{
		return $this->markerWritten;
	}

	/**
	 * Atomic write of the end marker (tmp + rename). Only the first call writes —
	 * a shutdown function after a successful run must not overwrite it.
	 * @param array{type?: int, message?: string, file?: string, line?: int}|null $fatalError from error_get_last()
	 */
	public function writeMarker(\Throwable|null $error = null, array|null $fatalError = null): void
	{
		if ($this->markerWritten) {
			return;
		}

		try {
			$this->flushProgress();
		} catch (\Throwable) {
			// Progress is best effort; the marker is what decides the run.
		}

		$failed = $this->explicitFail || $error !== null || $fatalError !== null;
		[$result, $truncated] = self::boundedResult($this->normalizeData($this->result));

		$errorPayload = null;

		if ($error !== null) {
			$errorPayload = [
				'class' => $error::class,
				'message' => self::cut($error->getMessage(), self::ERROR_MAX_BYTES),
				'trace' => self::cut(self::traceOf($error), self::ERROR_MAX_BYTES),
			];
		} elseif ($fatalError !== null) {
			$errorPayload = [
				'class' => 'FatalError',
				'message' => self::cut(\sprintf('%s in %s:%s', $fatalError['message'] ?? '', $fatalError['file'] ?? '?', $fatalError['line'] ?? '?'), self::ERROR_MAX_BYTES),
				'trace' => '',
			];
		}

		$marker = [
			'run_uuid' => $this->runUuid,
			'status' => $failed ? 'failed' : 'finished',
			'explicit_fail' => $this->explicitFail,
			'result' => $result,
			'result_truncated' => $truncated,
			'error' => $errorPayload,
			'peak_memory_mb' => (int) (\memory_get_peak_usage(true) / 1024 / 1024),
			'finished_at' => Clock::iso(Clock::nowMs()),
		];

		$path = $this->markerPath();
		$temporary = $path . '.tmp';

		// Data a handler produced may be invalid UTF-8 or NaN; a lost marker would
		// turn a successful run into a failed one and run it again (retry).
		if (\file_put_contents($temporary, self::encode($marker)) === false) {
			throw new \RuntimeException("Cannot write run marker {$temporary}");
		}

		if (!\rename($temporary, $path)) {
			throw new \RuntimeException("Cannot move run marker to {$path}");
		}

		$this->markerWritten = true;
	}

	/**
	 * JSON that never throws: invalid UTF-8 is substituted, NaN/INF become 0.
	 */
	public static function encode(mixed $value): string
	{
		$json = \json_encode(
			$value,
			\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
		);

		return \is_string($json) ? $json : 'null';
	}

	/**
	 * First `$maxBytes` bytes without splitting a UTF-8 character.
	 */
	public static function cut(string $text, int $maxBytes): string
	{
		if (\strlen($text) <= $maxBytes) {
			return $text;
		}

		return \mb_strcut($text, 0, $maxBytes) . "\n[… truncated, " . (\strlen($text) - $maxBytes) . ' B more]';
	}

	public static function current(): self|null
	{
		return self::$current;
	}

	public static function activate(self|null $context): void
	{
		self::$current = $context;
	}

	public static function runFile(string $stateDir, string $runUuid, string $suffix): string
	{
		return \rtrim($stateDir, '/') . '/runs/' . $runUuid . '.' . $suffix;
	}

	/**
	 * @return array{mixed, bool}
	 */
	public static function boundedResult(mixed $result): array
	{
		if ($result === null) {
			return [null, false];
		}

		$encoded = self::encode($result);

		if (\strlen($encoded) <= self::RESULT_MAX_BYTES) {
			return [$result, false];
		}

		return [['truncated' => true, 'bytes' => \strlen($encoded), 'head' => \mb_strcut($encoded, 0, self::RESULT_HEAD_BYTES)], true];
	}

	private function normalizeData(mixed $data): mixed
	{
		if ($data instanceof \Throwable) {
			return ['exception' => $data->getMessage(), 'trace' => ExceptionToJsonArray::getTraces($data)];
		}

		if (\is_string($data)) {
			return [$data];
		}

		return $data;
	}

	private function writeProgress(mixed $data, int $now): void
	{
		$this->hasPendingProgress = false;
		$this->pendingProgress = null;
		$this->lastProgressAtMs = $now;

		$data = $this->normalizeData($data);
		$encoded = self::encode($data);

		if (\strlen($encoded) > self::PROGRESS_MAX_BYTES) {
			$data = ['truncated' => true];
			$encoded = self::encode($data);
		}

		try {
			$connection = SchedulerDatabase::openForWrite($this->stateDir);
		} catch (SchedulerUnavailableException) {
			return;
		}

		try {
			$connection->transaction(function (SqliteConnection $connection) use ($data, $encoded, $now): void {
				// Under 500 MB free lqcron sets disk_low: progress is the one thing
				// not stored then, state events always are (§ 8.1).
				if ($connection->fetchValue("SELECT value FROM meta WHERE key = 'disk_low'") === '1') {
					return;
				}

				$connection->execute(
					"INSERT INTO run_events (run_uuid, type, payload, created_at) VALUES (?, 'progress', ?, ?)",
					[$this->runUuid, $encoded, $now],
				);

				if ($this->run === null) {
					return;
				}

				EventOutbox::insert($connection, 'run.progress', $this->commonPayload() + ['data' => $data], $this->runUuid, $now);
			});
		} finally {
			$connection->close();
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function commonPayload(): array
	{
		$run = $this->run ?? [];

		return [
			'run_uuid' => $this->runUuid,
			'job_uuid' => $run['job_uuid'] ?? null,
			'origin_uuid' => $run['origin_uuid'] ?? null,
			'cron_code' => $run['cron_code'] ?? null,
			'attempt' => isset($run['attempt']) ? (int) $run['attempt'] : null,
			'max_attempts' => isset($run['max_attempts']) ? (int) $run['max_attempts'] : null,
			'source' => $run['source'] ?? null,
			'scheduled_for' => isset($run['scheduled_for']) && \is_numeric($run['scheduled_for']) ? Clock::iso((int) $run['scheduled_for']) : null,
			'app_path' => $run['app_path'] ?? null,
		];
	}

	private static function traceOf(\Throwable $error): string
	{
		$trace = '';

		for ($current = $error; $current !== null; $current = $current->getPrevious()) {
			$trace .= ($trace === '' ? '' : "\nCaused by " . $current::class . ': ' . $current->getMessage() . "\n") . $current->getTraceAsString();
		}

		return $trace;
	}
}
