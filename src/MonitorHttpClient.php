<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use LiquidMonitorConnector\Exceptions\LiquidMonitorDisabledException;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * Sdílený HTTP transport k Liquid Monitor backendu. Drží version handshake
 * (detekce nepodporované verze konektoru + 426 Upgrade Required) na jednom
 * místě, aby ho cronový (`Cron`) i chybový (`ErrorReporter`) kanál neduplikovaly.
 */
class MonitorHttpClient
{
	/**
	 * Pošle POST na monitor. Vypnutý kanál (chybějící apiKey / `enabled:false`)
	 * se tiše přeskočí — pokud `$throw`, místo toho vyhodí
	 * `LiquidMonitorDisabledException` (cron scheduling to potřebuje rozlišit).
	 * @param array<string, mixed> $params Tělo requestu bez `apiKey` (ten se doplní z `$apiKey`).
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \LiquidMonitorConnector\Exceptions\LiquidMonitorDisabledException
	 * @throws \Exception
	 */
	public function post(string $url, string|null $apiKey, bool $enabled, array $params, bool $throw = false): void
	{
		$client = new Client();

		if (!$apiKey || !$enabled) {
			if ($throw) {
				throw new LiquidMonitorDisabledException();
			}

			return;
		}

		$options = [
			'json' => ['apiKey' => $apiKey] + $params,
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
