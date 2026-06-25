<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use LiquidMonitorConnector\Exceptions\LiquidMonitorDisabledException;
use LiquidMonitorConnector\Tasks\ExceptionToJsonArray;
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
 *     cron_id: int,
 *     job_id: int,
 *     started_ts: string,
 *     finished_ts: string,
 *     repeatCount: int,
 *     timeout: int
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

	private Request $httpRequest;

	private string|null $currentCronCode = null;
	
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
	 */
	public function setConfiguration(
		string $url,
		string|null $apiKey,
		bool $enabled,
		string|null $logUrl = null,
		string|null $logApiKey = null,
	): void {
		$this->url = $url;
		$this->apiKey = $apiKey;
		$this->enabled = $enabled;
		$this->logUrl = $logUrl ?? $url;
		$this->logApiKey = $logApiKey ?? $apiKey;
	}

	public function isCronRunning(string $cronCode): bool
	{
		$client = new Client();

		$response = $client->get(Strings::before($this->getUrl(), 'connector') . "front/cron/$cronCode/is-running", [
			'http_errors' => false,
			'json' => ['apiKey' => $this->getApiKey()],
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

		$response = $client->get(Strings::before($this->getUrl(), 'connector') . "front/cron/$cronCode/last-job-log", [
			'http_errors' => false,
			'json' => ['apiKey' => $this->getApiKey()],
			'headers' => [Version::HEADER_NAME => Version::CURRENT],
		]);
		$content = $response->getBody()->getContents();

		if ($response->getStatusCode() === 200 && $content) {
			try {
				return Json::decode($content, true);
			} catch (JsonException $e) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Get overview of all project crons together with their last-24h job log statistics
	 * in a single HTTP request (replaces N+1 calls to /front/cron + /front/cron/{code}/joblogs-stats).
	 * @return array<int, array<string, mixed>>|null
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function getCronOverview(): array|null
	{
		$client = new Client();

		$response = $client->get(Strings::before($this->getUrl(), 'connector') . 'front/cron/overview', [
			'http_errors' => false,
			'json' => ['apiKey' => $this->getApiKey()],
			'headers' => ['Accept' => 'application/json', Version::HEADER_NAME => Version::CURRENT],
			'timeout' => 15,
		]);
		$content = $response->getBody()->getContents();

		if ($response->getStatusCode() === 200 && $content) {
			try {
				$decoded = Json::decode($content, true);

				return $decoded['data'] ?? null;
			} catch (JsonException $e) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Get cron job logs statistics for the last 24 hours
	 * @param string $cronCode
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
	public function getCronJobLogsStats(string $cronCode): array|null
	{
		$client = new Client();

		$response = $client->get(Strings::before($this->getUrl(), 'connector') . "front/cron/$cronCode/joblogs-stats", [
			'http_errors' => false,
			'json' => ['apiKey' => $this->getApiKey()],
			'headers' => [Version::HEADER_NAME => Version::CURRENT],
		]);
		$content = $response->getBody()->getContents();

		if ($response->getStatusCode() === 200 && $content) {
			try {
				return Json::decode($content, true);
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
			$body = Json::decode($rawBody, forceArrays: true);
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
	 * @param array<mixed>|null $arguments
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
	): void {
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
	 * @param string $url
	 * @param string|null $apiKey API klíč kanálu, na který se posílá (cron / log).
	 * @param array<string, mixed> $params
	 * @param bool $throw
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \LiquidMonitorConnector\Exceptions\LiquidMonitorDisabledException
	 * @throws \Exception
	 */
	private function send(string $url, string|null $apiKey, array $params, bool $throw = false): void
	{
		$client = new Client();

		if (!$apiKey || !$this->isEnabled()) {
			if ($throw) {
				throw new LiquidMonitorDisabledException();
			}

			return;
		}

		$options = [
			'json' => ['apiKey' => $apiKey, 'jobId' => $this->getJobId()] + $params,
			'verify' => false,
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
				Version::HEADER_NAME => Version::CURRENT,
			],
			'timeout' => 15,
		];

		try {
			$response = $client->post($url, $options);

			if ($response->getHeaderLine(Version::STATUS_HEADER_NAME) === Version::STATUS_UNSUPPORTED) {
				$supported = $response->getHeaderLine('X-Connector-Supported-Versions');
				Debugger::log(
					\sprintf(
						'Liquid Monitor backend reports connector version %s as unsupported. Backend supports: %s. Upgrade liquiddesign/liquid-monitor-connector.',
						Version::CURRENT,
						$supported !== '' ? $supported : '(unknown)',
					),
					ILogger::WARNING,
				);
			}
		} catch (ClientException $e) {
			if ($e->getResponse()->getStatusCode() === 426) {
				$supported = $e->getResponse()->getHeaderLine('X-Connector-Supported-Versions');
				Debugger::log(
					\sprintf(
						'Liquid Monitor backend rejected connector version %s as unsupported (426 Upgrade Required). Backend supports: %s. Upgrade liquiddesign/liquid-monitor-connector.',
						Version::CURRENT,
						$supported !== '' ? $supported : '(unknown)',
					),
					ILogger::WARNING,
				);
			} else {
				Debugger::log($e, 'connector');
			}

			if ($throw) {
				throw $e;
			}
		} catch (\Exception $e) {
			Debugger::log($e, 'connector');

			if ($throw) {
				throw $e;
			}
		}
	}
}
