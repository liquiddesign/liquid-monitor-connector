<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

use GuzzleHttp\Client;
use Nette\Http\Request;

class Cron
{
	private const JOB_START_ENDPOINT = '/start-job';
	private const JOB_STATUS_ENDPOINT = '/status-job';
	private const JOB_FINISH_ENDPOINT = '/finish-job';
	
	private string $url;
	
	private string $apiKey;
	
	private Request $httpRequest;
	
	public function __construct(Request $httpRequest)
	{
		$this->httpRequest = $httpRequest;
	}
	
	public function setConfiguration(string $url, string $apiKey): void
	{
		$this->url = $url;
		$this->apiKey = $apiKey;
	}
	
	public function startJob(?string $logJson = null): void
	{
		$params = ['jobLog' => $logJson, 'pid' => \getmypid(), 'maxExecutionTime' => \ini_get('max_execution_time')];
		$this->send($this->getUrl() . self::JOB_START_ENDPOINT, $params);
	}
	
	public function finishJob(?string $logJson = null): void
	{
		$params = ['jobLog' => $logJson];
		$this->send($this->getUrl() . self::JOB_FINISH_ENDPOINT, $params);
	}
	
	public function statusJob(?string $logJson = null, ?string $message = null): void
	{
		$params = ['jobLog' => $logJson, 'message' => $message];
		$this->send($this->getUrl() . self::JOB_STATUS_ENDPOINT, $params);
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
			'headers' => [
				'ApiKey' => $this->getApiKey(),
			],
			'json' => ['jobId' => $this->httpRequest->getPost('jobId')] + $params,
			'verify' => false,
		];
		
		$client->post($url, $options);
	}
}
