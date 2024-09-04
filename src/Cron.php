<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use LiquidMonitorConnector\Tasks\ExceptionToJsonArray;
use Nette\Http\Request;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Tracy\Debugger;
use Tracy\ILogger;

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

	private Request $httpRequest;
	
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
	
	public function setConfiguration(string $url, string|null $apiKey, bool $enabled): void
	{
		$this->url = $url;
		$this->apiKey = $apiKey;
		$this->enabled = $enabled;
	}

	/**
	 * Schedule Cron if no POST data otherwise start Cron.
	 * @param string $cronCode
	 * @param array<mixed>|null|\Exception $data
	 * @param string|null $cronName If not null and Cron does not exist, create Cron.
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
	): bool {
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
			);
		} catch (\Exception $e) {
			Debugger::log($e, ILogger::EXCEPTION);

			return true;
		}

		return false;
	}
	
	public function scheduleJob(
		string $cronId,
		string|null $cronName = null,
		int $cronRepeatCount = 0,
		bool $cronCanRunConcurrently = false,
		bool $cronCanRunConcurrentlyCron = false,
		string|null $cronDescription = null,
		int|null $cronTimeout = null,
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
		];
		$this->send($this->getUrl() . self::JOB_SCHEDULE_ENDPOINT, $params, true);
	}

	/**
	 * @param array<mixed>|null|\Exception $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function startJob(array|\Exception|null|string $data = null): void
	{
		\register_shutdown_function([$this, 'shutdownFunction']);
		
		$params = ['data' => $this->processData($data)];
		$this->send($this->getUrl() . self::JOB_START_ENDPOINT, $params);
	}

	/**
	 * @param array<mixed>|null|\Exception $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function finishJob(array|\Exception|null|string $data = null): void
	{
		if (!$this->getJobId()) {
			return;
		}

		$params = ['data' => $this->processData($data)];
		$this->send($this->getUrl() . self::JOB_FINISH_ENDPOINT, $params);
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
		$this->send($this->getUrl() . self::JOB_PROGRESS_ENDPOINT, $params);
	}

	/**
	 * @param array<mixed>|null|\Exception $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function failJob(array|\Exception|null|string $data = null): void
	{
		if (!$this->getJobId()) {
			return;
		}

		$params = ['data' => $this->processData($data)];
		$this->send($this->getUrl() . self::JOB_FAIL_ENDPOINT, $params);
	}

	/**
	 * @param array<mixed> $data
	 * @param string $level
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function log(array $data, string $level): void
	{
		$params = $data + ['level' => $level];
		$this->send($this->getUrl() . self::LOG_ENDPOINT, $params);
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
		$this->failJob(data: ['reason' => 'Server shutdown']);
	}

	protected function getSkipMonitorParameter(): bool
	{
		if (!$this->getParameters() || !isset($this->getParameters()->skipMonitor)) {
			return false;
		}

		return (bool) $this->getParameters()->skipMonitor;
	}
	
	private function getUrl(): string
	{
		return $this->url;
	}
	
	private function getApiKey(): string|null
	{
		return $this->apiKey;
	}

	/**
	 * @param string $url
	 * @param array<string, mixed> $params
	 * @param bool $throw
	 */
	private function send(string $url, array $params, bool $throw = false): void
	{
		$client = new Client();
		$apiKey = $this->getApiKey();

		if (!$apiKey || !$this->isEnabled()) {
			if ($throw) {
				throw new \Exception('LiquidMonitor is disabled');
			}

			return;
		}

		$options = [
			'json' => ['apiKey' => $apiKey, 'jobId' => $this->getJobId()] + $params,
			'verify' => false,
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
			'timeout' => 15,
		];

		try {
			$client->post($url, $options);
		} catch (\Exception $e) {
//			Debugger::log($e, ILogger::EXCEPTION);

			if ($throw) {
				throw $e;
			}
		}
	}
}
