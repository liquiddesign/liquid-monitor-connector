<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

use GuzzleHttp\Client;
use Nette\Http\Request;
use Nette\Utils\Json;
use Tracy\Debugger;

class Cron
{
	private const JOB_SCHEDULE_ENDPOINT = '/schedule-job';
	private const JOB_START_ENDPOINT = '/start-job';
	private const JOB_PROGRESS_ENDPOINT = '/progress-job';
	private const JOB_FINISH_ENDPOINT = '/finish-job';
	private const JOB_FAIL_ENDPOINT = '/fail-job';
	
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
		
		return Json::decode($this->httpRequest->getRawBody());
	}
	
	public function setConfiguration(string $url, string $apiKey): void
	{
		$this->url = $url;
		$this->apiKey = $apiKey;
	}
	
	public function scheduleJob(int $cronId): void
	{
		$params = ['cronId' => $cronId];
		$this->send($this->getUrl() . self::JOB_SCHEDULE_ENDPOINT, $params);
	}
	
	public function startJob(?string $logJson = null): void
	{
		\register_shutdown_function([$this, 'shutdownFunction']);
		
		$params = ['jobLog' => $logJson, 'timeout' => (int) \ini_get('max_execution_time')];
		$this->send($this->getUrl() . self::JOB_START_ENDPOINT, $params);
	}
	
	public function finishJob(?string $logJson = null): void
	{
		$params = ['jobLog' => $logJson];
		$this->send($this->getUrl() . self::JOB_FINISH_ENDPOINT, $params);
	}
	
	public function progressJob(?string $logJson = null, ?string $message = null): void
	{
		$params = ['jobLog' => $logJson, 'message' => $message];
		$this->send($this->getUrl() . self::JOB_PROGRESS_ENDPOINT, $params);
	}
	
	public function failJob(?string $logJson = null, ?string $message = null): void
	{
		$params = ['jobLog' => $logJson, 'message' => $message];
		$this->send($this->getUrl() . self::JOB_FAIL_ENDPOINT, $params);
	}

	private function getJobId(): int|null
	{
		if (!$this->getParameters() || !isset($this->getParameters()->jobId)) {
			return null;
		}
		
		return (int) $this->getParameters()->jobId;
	}
	
	// phpcs:ignore
	private function shutdownFunction(): void
	{
		Debugger::log('Server shutdown');
		$this->failJob(message: 'Server shutdown');
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
	private function send(string $url, array $params): void
	{
		$client = new Client();
		
		$options = [
			'json' => ['apiKey' => $this->getApiKey(), 'jobId' => $this->getJobId()] + $params,
			'verify' => false,
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
		];
		
		$client->post($url, $options);
	}
}
