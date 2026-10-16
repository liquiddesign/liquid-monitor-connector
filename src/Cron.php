<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

use GuzzleHttp\Client;
use LiquidMonitorConnector\Exceptions\LiquidMonitorDisabledException;
use LiquidMonitorConnector\Scheduler\CronDefinitionCatalog;
use LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException;
use LiquidMonitorConnector\Scheduler\LocalQueue;
use LiquidMonitorConnector\Scheduler\LocalRunContext;
use LiquidMonitorConnector\Scheduler\SchedulerConfig;
use LiquidMonitorConnector\Tasks\ExceptionToJsonArray;
use LiquidMonitorConnector\Worker\CronJobHandlerCode;
use LiquidMonitorConnector\Worker\PullCron;
use Nette\Http\Request;
use Nette\Utils\Arrays;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Strings;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * @phpstan-type JobLogArray array{
 *     id: int,
 *     data: mixed|null,
 *     type: string,
 *     created_at: string,
 *     updated_at: string,
 *     cron_id: int|null,
 *     job_id: int|null,
 *     started_ts: string|null,
 *     finished_ts: string|null,
 *     repeatCount: int,
 *     timeout: int|null,
 *     uuid?: string,
 *     outcome?: string,
 *     source?: string
 * }
 */
class Cron
{
	private const JOB_SCHEDULE_ENDPOINT = '/schedule-job';
	private const JOB_START_ENDPOINT = '/start-job';
	private const JOB_PROGRESS_ENDPOINT = '/progress-job';
	private const JOB_FINISH_ENDPOINT = '/finish-job';
	private const JOB_FAIL_ENDPOINT = '/fail-job';
	private const LOG_ENDPOINT = '/log';

	private string $url;

	private string|null $apiKey;

	private bool $enabled;

	private string $logUrl;

	private string|null $logApiKey;

	private bool|string $verifyTls = true;

	private Request $httpRequest;

	private string|null $currentCronCode = null;

	private SchedulerConfig|null $schedulerConfig = null;

	private CronDefinitionCatalog|null $catalog = null;

	/** Set only with `scheduler.enabled` outside shadow mode: pull jobs go to lqcron, not LQDeck. */
	private LocalQueue|null $localQueue = null;
	
	public function __construct(Request $httpRequest)
	{
		$this->httpRequest = $httpRequest;
	}
	
	public function getParameters(): \stdClass|null
	{
		if (!$this->httpRequest->getRawBody()) {
			return null;
		}

		try {
			return Json::decode($this->httpRequest->getRawBody());
		} catch (JsonException $e) {
			return null;
		}
	}
	
	/**
	 * Crony (joby + read přehledy) míří na `$url`/`$apiKey`, logy/chyby na
	 * `$logUrl`/`$logApiKey`. Logové parametry jsou volitelné — když nejsou
	 * předané (null), spadnou na cronový kanál (zpětná kompatibilita).
	 * @param bool|string $verifyTls TLS ověření certifikátu monitoru (viz `MonitorHttpClient`):
	 *   `true` (default) = ověřovat, `false` = vypnuto (jen dev), string = cesta k CA bundlu.
	 */
	public function setConfiguration(
		string $url,
		string|null $apiKey,
		bool $enabled,
		string|null $logUrl = null,
		string|null $logApiKey = null,
		bool|string $verifyTls = true,
	): void {
		$this->url = $url;
		$this->apiKey = $apiKey;
		$this->enabled = $enabled;
		$this->logUrl = $logUrl ?? $url;
		$this->logApiKey = $logApiKey ?? $apiKey;
		$this->verifyTls = $verifyTls;
	}

	/**
	 * Wires the local scheduler (connector 4). Without a local queue every call
	 * behaves as in v3.
	 */
	public function setScheduler(SchedulerConfig $config, CronDefinitionCatalog $catalog, LocalQueue|null $localQueue = null): void
	{
		$this->schedulerConfig = $config;
		$this->catalog = $catalog;
		$this->localQueue = $localQueue;
	}

	public function getLocalQueue(): LocalQueue|null
	{
		return $this->localQueue;
	}

	public function getCatalog(): CronDefinitionCatalog|null
	{
		return $this->catalog;
	}

	/**
	 * Whether a code-managed cron with this code exists. With the local scheduler
	 * it asks lqcron's last export, otherwise the handlers compiled into the
	 * container — never LQDeck, so it works while LQDeck is down.
	 */
	public function isCronDefined(string $cronCode): bool
	{
		if ($this->localQueue !== null) {
			try {
				return $this->localQueue->isCronDefined($cronCode);
			} catch (SchedulerUnavailableException) {
				// Fall back to the compiled handlers below.
			}
		}

		return $this->catalog !== null && $this->catalog->has($cronCode);
	}

	/**
	 * Active run or a queued job. Local crons are answered by lqcron's database,
	 * push crons (and everything without the local scheduler) by LQDeck.
	 * @throws \LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException
	 */
	public function isCronRunning(string $cronCode): bool
	{
		if ($this->localQueue !== null && $this->catalog !== null && $this->catalog->has($cronCode)) {
			return $this->localQueue->isCronRunning($cronCode);
		}

		$client = new Client();

		$response = $client->get(Strings::before($this->getUrl(), 'connector') . "front/cron/$cronCode/is-running", [
			'http_errors' => false,
			'query' => ['apiKey' => $this->getApiKey()],
			'headers' => [Version::HEADER_NAME => Version::CURRENT],
		]);

		return $response->getStatusCode() === 200;
	}

	/**
	 * Get the last cron job log
	 * @param string $cronCode
	 * @return JobLogArray|null
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function getLastCronJobLog(string $cronCode): array|null
	{
		$client = new Client();

		try {
			$response = $client->get(Strings::before($this->getUrl(), 'connector') . "front/cron/$cronCode/last-job-log", [
				'http_errors' => false,
				'query' => ['apiKey' => $this->getApiKey()],
				'headers' => [Version::HEADER_NAME => Version::CURRENT],
			]);
		} catch (\GuzzleHttp\Exception\GuzzleException $e) {
			$local = $this->lastLocalRun($cronCode);

			if ($local !== null) {
				return $local;
			}

			throw $e;
		}

		$content = $response->getBody()->getContents();

		if ($response->getStatusCode() === 200 && $content) {
			try {
				return Json::decode($content, Json::FORCE_ARRAY);
			} catch (JsonException $e) {
				return null;
			}
		}

		// LQDeck unreachable or failing: the last local run is better than nothing (§ 9).
		if ($response->getStatusCode() >= 500 || $response->getStatusCode() === 426) {
			return $this->lastLocalRun($cronCode);
		}

		return null;
	}

	/**
	 * Get overview of all project crons together with their last-N-hours job log statistics
	 * in a single HTTP request (replaces N+1 calls to /front/cron + /front/cron/{code}/joblogs-stats).
	 * @param int $hours How many hours back to aggregate job log stats. Defaults to 24, capped server-side at 168.
	 * @return array<int, array<string, mixed>>|null
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function getCronOverview(int $hours = 24): array|null
	{
		$client = new Client();

		$response = $client->get(Strings::before($this->getUrl(), 'connector') . 'front/cron/overview', [
			'http_errors' => false,
			'query' => ['apiKey' => $this->getApiKey(), 'hours' => $hours],
			'headers' => ['Accept' => 'application/json', Version::HEADER_NAME => Version::CURRENT],
			'timeout' => 15,
		]);
		$content = $response->getBody()->getContents();

		if ($response->getStatusCode() === 200 && $content) {
			try {
				$decoded = Json::decode($content, Json::FORCE_ARRAY);

				return $decoded['data'] ?? null;
			} catch (JsonException $e) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Get cron job logs statistics for the last N hours (default 24)
	 * @param string $cronCode
	 * @param int $hours How many hours back to aggregate. Defaults to 24, capped server-side at 168.
	 * @return array{
	 *     cronCode: string,
	 *     cronName: string|null,
	 *     period: string,
	 *     totalRuns: int,
	 *     successfulRuns: int,
	 *     failedRuns: int,
	 *     runningRuns: int,
	 *     successRate: float,
	 *     lastSuccessfulRun: array{finished_ts: string, started_ts: string}|null,
	 *     lastFailedRun: array{created_at: string, started_ts: string}|null
	 * }|null
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function getCronJobLogsStats(string $cronCode, int $hours = 24): array|null
	{
		$client = new Client();

		$response = $client->get(Strings::before($this->getUrl(), 'connector') . "front/cron/$cronCode/joblogs-stats", [
			'http_errors' => false,
			'query' => ['apiKey' => $this->getApiKey(), 'hours' => $hours],
			'headers' => [Version::HEADER_NAME => Version::CURRENT],
		]);
		$content = $response->getBody()->getContents();

		if ($response->getStatusCode() === 200 && $content) {
			try {
				return Json::decode($content, Json::FORCE_ARRAY);
			} catch (JsonException $e) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Schedule Cron if no POST data otherwise start Cron.
	 * @param string $cronCode
	 * @param array<mixed>|null|\Exception $data
	 * @param string|null $cronName If not null and Cron does not exist, create Cron.
	 * @param int $cronRepeatCount
	 * @param bool $cronCanRunConcurrently
	 * @param bool $cronCanRunConcurrentlyCron
	 * @param string|null $cronDescription
	 * @param int|null $cronTimeout
	 * @param bool $createIfNotExists
	 * @param array<mixed>|null $arguments Custom parameters forwarded to the cron URL when the job runs.
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function scheduleOrStartJob(
		string $cronCode,
		array|\Exception|null $data = null,
		string|null $cronName = null,
		int $cronRepeatCount = 0,
		bool $cronCanRunConcurrently = false,
		bool $cronCanRunConcurrentlyCron = false,
		string|null $cronDescription = null,
		int|null $cronTimeout = null,
		bool $createIfNotExists = true,
		array|null $arguments = null,
	): bool {
		$this->currentCronCode = $cronCode;

		if ($this->getSkipMonitorParameter()) {
			return false;
		}

		if ($this->getJobId()) {
			$this->startJob($data);

			return true;
		}

		try {
			$this->scheduleJob(
				$cronCode,
				$cronName,
				$cronRepeatCount,
				$cronCanRunConcurrently,
				$cronCanRunConcurrentlyCron,
				$cronDescription,
				$cronTimeout,
				$createIfNotExists,
				$arguments,
			);
		} catch (LiquidMonitorDisabledException) {
			return true;
		} catch (\Exception $e) {
			Debugger::log($e, ILogger::EXCEPTION);

			return true;
		}

		return false;
	}

	/**
	 * Returns custom arguments forwarded by the monitor when the cron URL is being called.
	 * @return array<mixed>|null
	 */
	public function getArguments(): array|null
	{
		$rawBody = $this->httpRequest->getRawBody();

		if (!$rawBody) {
			return null;
		}

		try {
			$body = Json::decode($rawBody, Json::FORCE_ARRAY);
		} catch (JsonException $e) {
			Debugger::log($e, ILogger::EXCEPTION);

			return null;
		}

		if (!\is_array($body) || !isset($body['arguments']) || !\is_array($body['arguments'])) {
			return null;
		}

		return $body['arguments'];
	}

	/**
	 * Schedule a code-managed pull-model job: `$handlerClass` must carry a
	 * `#[PullCron]` attribute, which is the source of truth for the cron's
	 * metadata (name, description, repeatCount, concurrencyMode, timeout,
	 * maxQueueSize, timing) and is synced into the monitor on every call.
	 * Unlike {@see scheduleJob()}, no `cronUrl` and no client-side execution
	 * `timeout` are sent — pull crons have no push URL, and the only timeout
	 * that matters is the one declared on the attribute (`cronTimeout`).
	 * @param class-string $handlerClass
	 * @param array<mixed>|null $arguments
	 * @param int|null $delaySeconds When > 0, the monitor holds the job in the queue for this many
	 *   seconds before any worker may claim it (`cron_jobs.available_at`). Use it to retry a cron
	 *   whose remote system is temporarily down — without it the retry job is claimed by the very
	 *   next worker poll, seconds after the outage that caused it. Monitor-side cap is one day;
	 *   a monitor deployed before this parameter existed ignores it and runs the job immediately.
	 * With the local scheduler (`scheduler.enabled`) the job goes into lqcron's local
	 * queue instead and LQDeck is not contacted at all; failures are then
	 * {@see \LiquidMonitorConnector\Scheduler\Exceptions\SchedulingFailedException}
	 * (a {@see \GuzzleHttp\Exception\GuzzleException}, so existing catch blocks still apply).
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \LiquidMonitorConnector\Exceptions\LiquidMonitorDisabledException
	 * @throws \InvalidArgumentException When $handlerClass has no #[PullCron] attribute (remote queue).
	 */
	public function schedulePullJob(string $handlerClass, array|null $arguments = null, int|null $delaySeconds = null): void
	{
		if ($this->localQueue !== null) {
			$uuid = $this->localQueue->enqueue($handlerClass, $arguments, $delaySeconds);
			Debugger::log("Local cron job queued: $handlerClass ($uuid)", 'cron-schedule');

			return;
		}

		$reflection = new \ReflectionClass($handlerClass);
		$attributes = $reflection->getAttributes(PullCron::class);

		if ($attributes === []) {
			throw new \InvalidArgumentException(\sprintf(
				'Cron job handler class "%s" is missing the #[PullCron] attribute required by schedulePullJob().',
				$handlerClass,
			));
		}

		$pullCron = $attributes[0]->newInstance();
		$cronId = CronJobHandlerCode::fromClassName($handlerClass);

		$params = [
			'cronId' => $cronId,
			'executionMode' => 'pull',
			'cronTiming' => $pullCron->scheduleFor($this->schedulerConfig?->environment)
				?? \implode(PullCron::EXPRESSION_SEPARATOR, PullCron::normalizeExpressions($pullCron->schedule)),
			'cronName' => $pullCron->name,
			'cronRepeatCount' => $pullCron->repeatCount,
			'cronConcurrencyMode' => $pullCron->concurrencyMode,
			'cronDescription' => $pullCron->description,
			'cronTimeout' => $pullCron->timeout,
			'cronMaxQueueSize' => $pullCron->maxQueueSize,
			'createIfNotExists' => true,
			'arguments' => $arguments,
			'delaySeconds' => $delaySeconds,
		];
		$this->send($this->getUrl() . self::JOB_SCHEDULE_ENDPOINT, $this->getApiKey(), $params, true);

		Debugger::log(
			$delaySeconds !== null && $delaySeconds > 0
				? "Pull cron job scheduled: $cronId (delayed by {$delaySeconds}s)"
				: "Pull cron job scheduled: $cronId",
			'cron-schedule',
		);
	}

	/**
	 * @param array<mixed>|null $arguments
	 * @param int|null $delaySeconds When > 0, the job waits this many seconds in the queue before it
	 *   may run — see {@see schedulePullJob()}.
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \LiquidMonitorConnector\Exceptions\LiquidMonitorDisabledException
	 */
	public function scheduleJob(
		string $cronId,
		string|null $cronName = null,
		int $cronRepeatCount = 0,
		bool $cronCanRunConcurrently = false,
		bool $cronCanRunConcurrentlyCron = false,
		string|null $cronDescription = null,
		int|null $cronTimeout = null,
		bool $createIfNotExists = true,
		array|null $arguments = null,
		int|null $delaySeconds = null,
	): void {
		if ($this->localQueue !== null && $this->catalog !== null && $this->catalog->has($cronId)) {
			$definition = $this->catalog->get($cronId);
			\assert($definition !== null);
			$this->schedulePullJob($definition->handlerClass, $arguments, $delaySeconds);

			return;
		}

		$params = [
			'cronId' => $cronId,
			'timeout' => (int) \ini_get('max_execution_time'),
			'cronName' => $cronName,
			'cronUrl' => $this->httpRequest->getUrl(),
			'cronRepeatCount' => $cronRepeatCount,
			'cronCanRunConcurrently' => $cronCanRunConcurrently,
			'cronCanRunConcurrentlyCron' => $cronCanRunConcurrentlyCron,
			'cronDescription' => $cronDescription,
			'cronTimeout' => $cronTimeout,
			'createIfNotExists' => $createIfNotExists,
			'arguments' => $arguments,
			'delaySeconds' => $delaySeconds,
		];
		$this->send($this->getUrl() . self::JOB_SCHEDULE_ENDPOINT, $this->getApiKey(), $params, true);

		Debugger::log("Cron job scheduled: $cronId", 'cron-schedule');
	}

	/**
	 * @param array<mixed>|null|\Exception $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function startJob(array|\Exception|null|string $data = null): void
	{
		\register_shutdown_function([$this, 'shutdownFunction']);
		
		$params = ['data' => $this->processData($data)];
		$this->send($this->getUrl() . self::JOB_START_ENDPOINT, $this->getApiKey(), $params);
	}

	/**
	 * @param array<mixed>|null|\Exception $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function finishJob(array|\Exception|null|string $data = null): void
	{
		$local = LocalRunContext::current();

		if ($local !== null) {
			$local->finish($data);

			return;
		}

		if (!$this->getJobId()) {
			$cronCode = $this->currentCronCode ?? 'unknown';
			Debugger::log("Cron job finished (not monitored): $cronCode", 'cron-finish');

			return;
		}

		$memoryUsage = (int) (\memory_get_peak_usage(true) / 1024 / 1024);

		$params = ['data' => $this->processData($data), 'ram' => $memoryUsage];
		$this->send($this->getUrl() . self::JOB_FINISH_ENDPOINT, $this->getApiKey(), $params);
	}

	/**
	 * @param array<mixed>|null|\Exception $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function progressJob(array|\Exception|null|string $data = null): void
	{
		$local = LocalRunContext::current();

		if ($local !== null) {
			try {
				$local->progress($data);
			} catch (\Throwable $e) {
				// Progress must never fail the job it reports on.
				Debugger::log($e, 'cron-progress');
			}

			return;
		}

		if (!$this->getJobId()) {
			return;
		}

		$params = ['data' => $this->processData($data)];
		$this->send($this->getUrl() . self::JOB_PROGRESS_ENDPOINT, $this->getApiKey(), $params);
	}

	/**
	 * @param array<mixed>|null|\Exception $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function failJob(array|\Exception|null|string $data = null): void
	{
		$local = LocalRunContext::current();

		if ($local !== null) {
			$local->fail($data);

			return;
		}

		if (!$this->getJobId()) {
			$cronCode = $this->currentCronCode ?? 'unknown';
			$dataInfo = $data ? ' - ' . \json_encode($this->processData($data)) : '';
			Debugger::log("Cron job failed (not monitored): $cronCode$dataInfo", 'cron-fail');

			return;
		}

		$memoryUsage = (int) (\memory_get_peak_usage(true) / 1024 / 1024);

		$params = ['data' => $this->processData($data), 'ram' => $memoryUsage];
		$this->send($this->getUrl() . self::JOB_FAIL_ENDPOINT, $this->getApiKey(), $params);
	}

	/**
	 * @param array<mixed> $data
	 * @param string $level
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function log(array $data, string $level): void
	{
		$params = $data + ['level' => $level];
		$this->send($this->getLogUrl() . self::LOG_ENDPOINT, $this->getLogApiKey(), $params);
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	public function getJobId(): string|null
	{
		if (!$this->getParameters() || !isset($this->getParameters()->jobId)) {
			return null;
		}

		return (string) $this->getParameters()->jobId;
	}

	public function getUrl(): string
	{
		return $this->url;
	}

	public function getApiKey(): string|null
	{
		return $this->apiKey;
	}

	public function getLogUrl(): string
	{
		return $this->logUrl;
	}

	public function getLogApiKey(): string|null
	{
		return $this->logApiKey;
	}

	public function getVerifyTls(): bool|string
	{
		return $this->verifyTls;
	}

	/**
	 * @param array<mixed>|\Exception|null $data
	 * @return array<mixed>|null
	 */
	protected function processData(array|null|\Exception|string $data): array|null
	{
		if (!$data) {
			return null;
		}

		if ($data instanceof \Exception) {
			return ['exception' => $data->getMessage(), 'trace' => ExceptionToJsonArray::getTraces($data)];
		}

		if (\is_string($data)) {
			return [$data];
		}

		return $data;
	}

	protected function shutdownFunction(): void
	{
		$data = ['reason' => 'PHP shutdown function triggered. Did you forget to call finishJob() or failJob() in your code?'];

		$error = \error_get_last();

		if ($error !== null && Arrays::contains([\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR], $error['type'])) {
			$data['error'] = [
				'type' => $error['type'],
				'message' => $error['message'],
				'file' => $error['file'],
				'line' => $error['line'],
			];
		}

		$this->failJob(data: $data);
	}

	protected function getSkipMonitorParameter(): bool
	{
		if (!$this->getParameters() || !isset($this->getParameters()->skipMonitor)) {
			return false;
		}

		return (bool) $this->getParameters()->skipMonitor;
	}

	/**
	 * Cronové endpointy posílají přes sdílený transport; `jobId` příchozího
	 * monitor requestu se přidává do těla (stejně jako dřív).
	 * @param string $url
	 * @param string|null $apiKey API klíč kanálu, na který se posílá (cron / log).
	 * @param array<string, mixed> $params
	 * @param bool $throw
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \LiquidMonitorConnector\Exceptions\LiquidMonitorDisabledException
	 * @throws \Exception
	 */
	protected function send(string $url, string|null $apiKey, array $params, bool $throw = false): void
	{
		(new MonitorHttpClient($this->verifyTls))->post($url, $apiKey, $this->isEnabled(), ['jobId' => $this->getJobId()] + $params, $throw);
	}

	/**
	 * @return JobLogArray|null
	 */
	private function lastLocalRun(string $cronCode): array|null
	{
		if ($this->localQueue === null) {
			return null;
		}

		try {
			return $this->localQueue->lastRun($cronCode);
		} catch (SchedulerUnavailableException) {
			return null;
		}
	}
}
