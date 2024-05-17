<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Nette\Http\Request;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Tracy\Debugger;

class Cron
{
	private const JOB_SCHEDULE_ENDPOINT = '/schedule-job';
	private const JOB_START_ENDPOINT = '/start-job';
	private const JOB_PROGRESS_ENDPOINT = '/progress-job';
	private const JOB_FINISH_ENDPOINT = '/finish-job';
	private const JOB_FAIL_ENDPOINT = '/fail-job';
	private const LOG_ENDPOINT = '/log';

	private string $url;
	
	private string $apiKey;
	
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
	
	public function setConfiguration(string $url, string $apiKey): void
	{
		$this->url = $url;
		$this->apiKey = $apiKey;
	}

	/**
	 * @param string $cronId
	 * @param array<mixed>|null $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function scheduleOrStartJob(string $cronId, ?array $data = null): bool
	{
		if ($this->getJobId()) {
			$this->startJob($data);

			return true;
		}

		try {
			$this->scheduleJob($cronId);
		} catch (\Exception $e) {
			return true;
		}

		return false;
	}
	
	public function scheduleJob(string $cronId): void
	{
		$params = ['cronId' => $cronId, 'timeout' => (int) \ini_get('max_execution_time')];
		$this->send($this->getUrl() . self::JOB_SCHEDULE_ENDPOINT, $params, true);
	}

	/**
	 * @param array<mixed>|null $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function startJob(?array $data = null): void
	{
		\register_shutdown_function([$this, 'shutdownFunction']);
		
		$params = ['data' => $data];
		$this->send($this->getUrl() . self::JOB_START_ENDPOINT, $params);
	}

	/**
	 * @param array<mixed>|null $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function finishJob(?array $data = null): void
	{
		$params = ['data' => $data];
		$this->send($this->getUrl() . self::JOB_FINISH_ENDPOINT, $params);
	}

	/**
	 * @param array<mixed>|null $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function progressJob(?array $data = null): void
	{
		$params = ['data' => $data];
		$this->send($this->getUrl() . self::JOB_PROGRESS_ENDPOINT, $params);
	}

	/**
	 * @param array<mixed>|null $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function failJob(?array $data = null): void
	{
		$params = ['data' => $data];
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

	protected function shutdownFunction(): void
	{
		$this->failJob(data: ['reason' => 'Server shutdown']);
	}

	private function getJobId(): string|null
	{
		if (!$this->getParameters() || !isset($this->getParameters()->jobId)) {
			return null;
		}
		
		return (string) $this->getParameters()->jobId;
	}
	
	private function getUrl(): string
	{
		return $this->url;
	}
	
	private function getApiKey(): string
	{
		return $this->apiKey;
	}
	
	/**
	 * @param string $url
	 * @param array<string, mixed> $params
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	private function send(string $url, array $params, bool $throw = false): void
	{
		$client = new Client();
		
		$options = [
			'json' => ['apiKey' => $this->getApiKey(), 'jobId' => $this->getJobId()] + $params,
			'verify' => false,
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
			'timeout' => 5,
		];

		try {
			$client->post($url, $options);
		} catch (GuzzleException $e) {
			if ($throw) {
				throw $e;
			}
		}
	}
}
