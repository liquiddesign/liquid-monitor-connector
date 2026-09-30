<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry\Agent;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use LiquidMonitorConnector\Version;
use Nette\Utils\FileSystem;

// phpcs:disable Generic.PHP.NoSilencedErrors.Discouraged -- nedostupný disk nebo monitor nesmí agenta shodit

/**
 * Posílá minutové záznamy do LQDecku (`POST {connector url}/perf`). Když monitor neodpoví,
 * záznamy se odloží do fronty na disku (strop `maxSpoolBytes`, nad ním se zahazují) a pošlou
 * se s dalším úspěšným flushem — agent tak přečká výpadek LQDecku, aniž by cokoli blokoval.
 */
final class MonitorSink implements Sink
{
	public const ENDPOINT = '/perf';

	private const RESEND_BATCH = 20;

	private ClientInterface $client;

	public function __construct(
		private string $connectorUrl,
		private string $apiKey,
		private string $spoolDirectory,
		private int $maxSpoolBytes = 50 * 1024 * 1024,
		?ClientInterface $client = null,
	) {
		$this->client = $client ?? new Client(['timeout' => 10, 'connect_timeout' => 3]);
	}

	public function write(array $minutes): void
	{
		if ($minutes === []) {
			return;
		}

		if (!$this->post($minutes)) {
			$this->spool($minutes);

			return;
		}

		$this->resendSpool();
	}

	/**
	 * @param list<array<string, mixed>> $minutes
	 */
	private function post(array $minutes): bool
	{
		try {
			$response = $this->client->request('POST', \rtrim($this->connectorUrl, '/') . self::ENDPOINT, [
				'json' => ['apiKey' => $this->apiKey, 'minutes' => $minutes],
				'headers' => ['Accept' => 'application/json', Version::HEADER_NAME => Version::CURRENT],
				'http_errors' => false,
			]);
		} catch (\Throwable) {
			return false;
		}

		$status = $response->getStatusCode();

		// 4xx kromě 408/429 se opakováním nespraví (špatný klíč, nevalidní data) — nezahlcovat frontu
		return $status < 300 || ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429);
	}

	/**
	 * @param list<array<string, mixed>> $minutes
	 */
	private function spool(array $minutes): void
	{
		$file = $this->spoolFile();

		try {
			FileSystem::createDir($this->spoolDirectory);
		} catch (\Throwable) {
			return;
		}

		\clearstatcache(true, $file);

		if (\is_file($file) && (int) \filesize($file) >= $this->maxSpoolBytes) {
			return;
		}

		$line = \json_encode($minutes, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);

		if ($line === false) {
			return;
		}

		@\file_put_contents($file, $line . "\n", \FILE_APPEND | \LOCK_EX);
	}

	private function resendSpool(): void
	{
		$file = $this->spoolFile();
		$claimed = $file . '.' . \getmypid();

		// Přejmenování je atomické — dva překrývající se agenti nepošlou totéž dvakrát.
		if (!\is_file($file) || !@\rename($file, $claimed)) {
			return;
		}

		$lines = @\file($claimed, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

		try {
			FileSystem::delete($claimed);
		} catch (\Throwable) {
			// soubor zůstane, příště se nepřečte (jiný pid) — lepší než poslat data dvakrát
		}

		if ($lines === false) {
			return;
		}

		foreach ($lines as $index => $line) {
			/** @var list<array<string, mixed>>|mixed $minutes */
			$minutes = \json_decode($line, true);

			if (!\is_array($minutes) || !\array_is_list($minutes)) {
				continue;
			}

			if ($index < self::RESEND_BATCH && $this->post($minutes)) {
				continue;
			}

			$this->spool($minutes);
		}
	}

	private function spoolFile(): string
	{
		return $this->spoolDirectory . '/spool.jsonl';
	}
}
