<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

use Nette\Http\Request;
use Nette\Utils\Json;
use Nette\Utils\JsonException;

/**
 * Chybový/logový kanál Liquid Monitoru — nezávislý na cron logice. Posílá
 * aplikační chyby (`LiquidMonitorLogger`) na `/log`, takže projekt může napojit
 * jen sběr chyb (pro AI úkoly) bez registrace cronové služby `Cron`.
 */
class ErrorReporter
{
	private const LOG_ENDPOINT = '/log';

	private string $url;

	private string|null $apiKey;

	private bool $enabled;

	public function __construct(private Request $httpRequest)
	{
	}

	public function setConfiguration(string $url, string|null $apiKey, bool $enabled): void
	{
		$this->url = $url;
		$this->apiKey = $apiKey;
		$this->enabled = $enabled;
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function log(array $data, string $level): void
	{
		$params = ['jobId' => $this->getJobId()] + $data + ['level' => $level];
		(new MonitorHttpClient())->post($this->url . self::LOG_ENDPOINT, $this->apiKey, $this->enabled, $params);
	}

	public function getUrl(): string
	{
		return $this->url;
	}

	public function getApiKey(): string|null
	{
		return $this->apiKey;
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	/**
	 * jobId přicházejícího monitor requestu — chyba vzniklá během monitorovaného
	 * cron jobu tak zůstane provázaná s jeho job logem. V error-only režimu (bez
	 * cronů) je vždy null, což je správně.
	 */
	public function getJobId(): string|null
	{
		$parameters = $this->getParameters();

		if (!$parameters || !isset($parameters->jobId)) {
			return null;
		}

		return (string) $parameters->jobId;
	}

	private function getParameters(): \stdClass|null
	{
		if (!$this->httpRequest->getRawBody()) {
			return null;
		}

		try {
			return Json::decode($this->httpRequest->getRawBody());
		} catch (JsonException) {
			return null;
		}
	}
}
