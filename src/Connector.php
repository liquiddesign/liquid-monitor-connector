<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

use GuzzleHttp\Client;

class Connector
{
	private string $url;

	private string $apiKey;
	
	public function setConfiguration(string $url, string $apiKey): void
	{
		$this->url = $url;
		$this->apiKey = $apiKey;
	}

	public function getUrl(): string
	{
		return $this->url;
	}

	public function getApiKey(): string
	{
		return $this->apiKey;
	}
	
	public function sendMessage(string $message): void
	{
		$client = new Client();
		
		$options = [
			'headers' => [
				'ApiKey' => $this->getApiKey(),
			],
			'json' => $message,
			'verify' => false,
		];
		
		$client->post($this->getUrl(), $options);
	}
}
