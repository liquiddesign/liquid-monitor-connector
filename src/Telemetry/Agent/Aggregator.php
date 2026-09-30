<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry\Agent;

use LiquidMonitorConnector\Telemetry\Recorder;
use LiquidMonitorConnector\Telemetry\StormBridge;

/**
 * Skládá datagramy recorderu do minutových bucketů.
 *
 * - requesty po (prostředí, route, tagy) s histogramem doby, fázemi a podílem studených requestů;
 * - operace (SQL, HTTP, QI, …) po (prostředí, typ, klíč) — počet, součet, max, chyby, v kolika
 *   requestech se objevila a nejvyšší počet v jednom requestu (N+1);
 * - vzorkované / pomalé / chybové requesty jako trasy;
 * - texty dlouhých klíčů (hash → SQL).
 *
 * Kardinalita je shora omezená, přebytek jde do klíče {@see Recorder::KEY_OVERFLOW}.
 *
 * Klíče operací se tu normalizují (SQL: literály → `?`) — mimo request, takže to aplikaci nic nestojí.
 * Varianty téhož dotazu se nejdřív sečtou v rámci jednoho datagramu, aby `max_per_request` ukázal N+1
 * i u dotazů s hodnotami vloženými přímo do SQL. Dlouhý klíč (`h:<crc32>`) jde normalizovat jen když
 * datagram nese jeho text (`tx`, recorder ho přibaluje k nejdražším); jinak zůstane hash.
 */
final class Aggregator
{
	/**
	 * @var array<int, array{requests: array<string, array<string, mixed>>, ops: array<string, array<string, mixed>>, traces: list<array<string, mixed>>, texts: array<string, string>, received: int}>
	 */
	private array $minutes = [];

	private int $invalid = 0;

	/** @var array<string, \Closure(string): string> */
	private array $normalizers;

	/**
	 * @param array<string, \Closure(string): string>|null $normalizers typ => normalizace klíče; null = výchozí (SQL)
	 */
	public function __construct(private int $maxTracesPerMinute = 200, private int $maxKeysPerMinute = 2000, ?array $normalizers = null)
	{
		$this->normalizers = $normalizers ?? [StormBridge::TYPE => StormBridge::normalizeSql(...)];
	}

	public function ingest(string $datagram): bool
	{
		$payload = \json_decode($datagram, true, 16);

		if (!\is_array($payload) || ($payload['v'] ?? null) !== Recorder::PROTOCOL_VERSION || !\is_numeric($payload['ts'] ?? null) || !\is_numeric($payload['ms'] ?? null)) {
			$this->invalid++;

			return false;
		}

		$minute = \intdiv((int) $payload['ts'], 60) * 60;
		$this->minutes[$minute] ??= ['requests' => [], 'ops' => [], 'traces' => [], 'texts' => [], 'received' => 0];
		$bucket = &$this->minutes[$minute];
		$bucket['received']++;

		$env = \is_string($payload['env'] ?? null) ? $payload['env'] : '';
		$kind = \is_string($payload['k'] ?? null) ? $payload['k'] : 'req';
		$route = \is_string($payload['route'] ?? null) ? $payload['route'] : '(unknown)';
		$tags = \is_array($payload['tags'] ?? null) ? $payload['tags'] : [];
		\ksort($tags);
		$ms = (float) $payload['ms'];
		$error = ($payload['err'] ?? null) !== null || (\is_int($payload['st'] ?? null) && $payload['st'] >= 500);
		$cold = ($payload['proc']['cold'] ?? null) === true;

		$requestKey = $env . '|' . $kind . '|' . $route . '|' . \json_encode($tags);

		if (!isset($bucket['requests'][$requestKey]) && \count($bucket['requests']) >= $this->maxKeysPerMinute) {
			$route = Recorder::KEY_OVERFLOW;
			$tags = [];
			$requestKey = $env . '|' . $kind . '|' . $route . '|[]';
		}

		$bucket['requests'][$requestKey] ??= [
			'env' => $env, 'kind' => $kind, 'route' => $route, 'tags' => $tags,
			'count' => 0, 'sum_ms' => 0.0, 'max_ms' => 0.0, 'errors' => 0, 'hist' => [],
			'cold' => 0, 'cold_sum_ms' => 0.0, 'compiled' => 0, 'phases' => [], 'mem_max' => 0.0, 'overhead_us' => 0.0,
		];
		$request = &$bucket['requests'][$requestKey];
		$request['count']++;
		$request['sum_ms'] += $ms;
		$request['max_ms'] = \max($request['max_ms'], $ms);
		$request['errors'] += $error ? 1 : 0;
		Histogram::add($request['hist'], $ms);
		$request['mem_max'] = \max($request['mem_max'], \is_numeric($payload['mem'] ?? null) ? (float) $payload['mem'] : 0.0);
		$request['overhead_us'] += \is_numeric($payload['ovh'] ?? null) ? (float) $payload['ovh'] : 0.0;

		if ($cold) {
			$request['cold']++;
			$request['cold_sum_ms'] += $ms;
			$request['compiled'] += \is_int($payload['proc']['comp'] ?? null) ? $payload['proc']['comp'] : 0;
		}

		foreach (\is_array($payload['ph'] ?? null) ? $payload['ph'] : [] as $phase => $phaseMs) {
			if (\is_numeric($phaseMs)) {
				$request['phases'][$phase] = ($request['phases'][$phase] ?? 0.0) + (float) $phaseMs;
			}
		}

		unset($request);

		$texts = \is_array($payload['tx'] ?? null) ? $payload['tx'] : [];

		foreach (\is_array($payload['keys'] ?? null) ? $payload['keys'] : [] as $type => $byKey) {
			if (!\is_array($byKey)) {
				continue;
			}

			foreach ($this->mergeNormalized((string) $type, $byKey, $texts) as $key => $stats) {
				$this->addOperation($bucket, $env, (string) $type, (string) $key, $stats);
			}
		}

		foreach (\is_array($payload['tx'] ?? null) ? $payload['tx'] : [] as $hash => $text) {
			if (\is_string($text)) {
				$bucket['texts'][(string) $hash] = $text;
			}
		}

		if (($payload['spans'] ?? null) !== null && \count($bucket['traces']) < $this->maxTracesPerMinute) {
			$bucket['traces'][] = $payload;
		}

		unset($bucket);

		return true;
	}

	/**
	 * Vydá a zapomene minuty starší než `$beforeMinute` (epoch začátku minuty), s null všechny.
	 * @return list<array<string, mixed>>
	 */
	public function drain(?int $beforeMinute = null): array
	{
		$drained = [];

		foreach ($this->minutes as $minute => $bucket) {
			if ($beforeMinute !== null && $minute >= $beforeMinute) {
				continue;
			}

			$drained[] = [
				'v' => Recorder::PROTOCOL_VERSION,
				'minute' => $minute,
				'agent' => \getmypid(),
				'received' => $bucket['received'],
				'invalid' => $this->invalid,
				'requests' => \array_values($bucket['requests']),
				'ops' => \array_values($bucket['ops']),
				'traces' => $bucket['traces'],
				'texts' => $bucket['texts'] === [] ? new \stdClass() : $bucket['texts'],
			];
			$this->invalid = 0;
			unset($this->minutes[$minute]);
		}

		return $drained;
	}

	/**
	 * Klíče jednoho datagramu po normalizaci, varianty téhož dotazu sečtené.
	 * @param array<mixed> $entries [[ref, počet, součet ms, max ms, chyby], …]
	 * @param array<mixed> $texts hash => text
	 * @return array<string, array{int, float, float, int}>
	 */
	private function mergeNormalized(string $type, array $entries, array $texts): array
	{
		$normalizer = $this->normalizers[$type] ?? null;
		$merged = [];

		foreach ($entries as $entry) {
			if (!\is_array($entry) || \count($entry) !== 5 || !\is_string($entry[0] ?? null)) {
				continue;
			}

			$key = $entry[0];

			if ($normalizer !== null) {
				$text = \str_starts_with($key, 'h:') ? ($texts[$key] ?? null) : $key;

				if (\is_string($text)) {
					try {
						$key = $normalizer($text);
					} catch (\Throwable) {
						// nenormalizovaný klíč je pořád lepší než zahozený
					}
				}
			}

			$current = $merged[$key] ?? [0, 0.0, 0.0, 0];
			$merged[$key] = [
				$current[0] + (int) $entry[1],
				$current[1] + (float) $entry[2],
				\max($current[2], (float) $entry[3]),
				$current[3] + (int) $entry[4],
			];
		}

		return $merged;
	}

	/**
	 * @param array{requests: array<string, array<string, mixed>>, ops: array<string, array<string, mixed>>, traces: list<array<string, mixed>>, texts: array<string, string>, received: int} $bucket
	 * @param array<mixed> $stats [počet, součet ms, max ms, chyby]
	 */
	private function addOperation(array &$bucket, string $env, string $type, string $key, array $stats): void
	{
		[$count, $sum, $max, $errors] = \array_values($stats);
		$operationKey = $env . '|' . $type . '|' . $key;

		if (!isset($bucket['ops'][$operationKey]) && \count($bucket['ops']) >= $this->maxKeysPerMinute) {
			$key = Recorder::KEY_OVERFLOW;
			$operationKey = $env . '|' . $type . '|' . $key;
		}

		$bucket['ops'][$operationKey] ??= ['env' => $env, 'type' => $type, 'key' => $key, 'count' => 0, 'sum_ms' => 0.0, 'max_ms' => 0.0, 'errors' => 0, 'requests' => 0, 'max_per_request' => 0];
		$operation = &$bucket['ops'][$operationKey];
		$operation['count'] += (int) $count;
		$operation['sum_ms'] += (float) $sum;
		$operation['max_ms'] = \max($operation['max_ms'], (float) $max);
		$operation['errors'] += (int) $errors;
		$operation['requests']++;
		$operation['max_per_request'] = \max($operation['max_per_request'], (int) $count);
		unset($operation);
	}
}
