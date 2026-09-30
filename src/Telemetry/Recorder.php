<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry;

/**
 * Výkonová telemetrie jednoho requestu — měří jen do paměti, na konci pošle jeden datagram.
 *
 * Pravidla, na kterých stojí „nenaruší výkon":
 * - v běžné cestě jen `hrtime()` a zápis skaláru do pole, žádné I/O ani objekty na span;
 * - paměť je shora omezená (`maxSpans`, `maxKeysPerType`, `maxSlowSpans`), přebytek se jen sečte;
 * - `debug_backtrace()` jen u pomalé operace a nejvýš `maxBacktraces`× na request;
 * - na konci jediný `json_encode` + UDP `fwrite`, který nečeká na potvrzení;
 * - každá chyba telemetrie se spolkne — telemetrie nikdy nerozbije request.
 *
 * Statická třída schválně: volá se z háčků hluboko v kódu (StORM, Guzzle, projektové služby),
 * kam se DI nedostane, a statické volání je nejlevnější. Stav žije jen po dobu requestu.
 *
 * Projekt může dopojit vlastní operace:
 *
 *     $start = Recorder::begin();
 *     ...
 *     Recorder::end('qi', $method, $start, $failed);
 *
 *     $result = Recorder::measure('daemon', 'search', fn () => $client->search($query));
 *
 *     Recorder::tag('shop', 'abel'); // jen hodnoty s malou kardinalitou, ne identita uživatele
 */
final class Recorder
{
	public const PROTOCOL_VERSION = 1;

	public const KEY_OVERFLOW = '_other';

	/** Klíče delší než tohle (typicky SQL) jdou jako `h:<crc32>`, text se přibalí jen k nejdražším. */
	private const LONG_KEY = 200;

	private const TEXT_LIMIT = 4000;

	private const TEXTS_PER_TYPE = 10;

	private const BACKTRACE_FRAMES = 12;

	private static bool $active = false;

	private static ?TelemetryConfig $config = null;

	private static ?Transport $transport = null;

	private static int $hrStart = 0;

	private static float $requestStart = 0.0;

	private static ?float $bootMs = null;

	/** @var array<string, int> fáze => hrtime začátku */
	private static array $phases = [];

	private static ?string $route = null;

	private static ?string $error = null;

	/** @var array<string, string|int|float|bool> */
	private static array $tags = [];

	/** @var array<string, array<string, array{int, int, int, int}>> typ => klíč => [počet, součet ns, max ns, chyby] */
	private static array $keys = [];

	/** @var list<array{string, int, int, string}> [typ, start ns od začátku, doba ns, klíč] */
	private static array $spans = [];

	private static int $spanCount = 0;

	private static int $droppedSpans = 0;

	/** @var list<array{t: string, k: string, ms: float, err: bool, bt?: list<string>}> */
	private static array $slow = [];

	private static int $backtraces = 0;

	private static int $slowNs = 0;

	private static int $maxSpans = 0;

	private static int $maxKeys = 0;

	/** @var array{int, float}|null */
	private static ?array $opcacheStart = null;

	private static bool $shutdownRegistered = false;

	public static function start(TelemetryConfig $config, ?Transport $transport = null, ?float $requestStart = null): void
	{
		if (self::$active || !$config->isActiveForCurrentSapi()) {
			return;
		}

		self::reset();
		self::$config = $config;
		self::$transport = $transport ?? new UdpTransport($config->host, $config->port);
		self::$hrStart = (int) \hrtime(true);
		self::$slowNs = (int) ($config->slowSpanMs * 1000000);
		self::$maxSpans = $config->maxSpans;
		self::$maxKeys = $config->maxKeysPerType;

		$wallStart = \microtime(true);
		// V CLI je REQUEST_TIME_FLOAT start skriptu, ne „requestu" — fáze boot by rostla s během procesu.
		$serverStart = \PHP_SAPI === 'cli' ? null : self::server('REQUEST_TIME_FLOAT');
		$requestStart ??= \is_float($serverStart) ? $serverStart : null;
		self::$requestStart = $requestStart ?? $wallStart;
		self::$bootMs = $requestStart !== null ? \max(0.0, ($wallStart - $requestStart) * 1000) : null;
		self::$opcacheStart = OpcacheProbe::read();
		self::$active = true;

		if (self::$shutdownRegistered) {
			return;
		}

		// Pojistka pro requesty, které skončí exit()em nebo fatalem dřív, než host zavolá flush().
		\register_shutdown_function([self::class, 'flushOnShutdown']);
		self::$shutdownRegistered = true;
	}

	public static function isActive(): bool
	{
		return self::$active;
	}

	/**
	 * Začátek fáze requestu (startup, presenter, send, …). `$once` = nepřepisovat, když už fáze začala.
	 */
	public static function mark(string $phase, bool $once = false): void
	{
		if (!self::$active || ($once && isset(self::$phases[$phase]))) {
			return;
		}

		self::$phases[$phase] = (int) \hrtime(true);
	}

	public static function setRoute(string $route, bool $overwrite = false): void
	{
		if (!self::$active || (self::$route !== null && !$overwrite)) {
			return;
		}

		self::$route = $route;
	}

	public static function markError(?string $error = null): void
	{
		if (!self::$active) {
			return;
		}

		self::$error ??= $error ?? 'error';
	}

	public static function tag(string $name, string|int|float|bool $value): void
	{
		if (!self::$active) {
			return;
		}

		self::$tags[$name] = $value;
	}

	/**
	 * @return int hrtime začátku, 0 když telemetrie neběží ({@see end()} pak nic nedělá)
	 */
	public static function begin(): int
	{
		return self::$active ? (int) \hrtime(true) : 0;
	}

	public static function end(string $type, string $key, int $start, bool $error = false): void
	{
		if (!self::$active || $start === 0) {
			return;
		}

		self::record($type, $key, (int) \hrtime(true) - $start, $error, $start);
	}

	/**
	 * @template T
	 * @param \Closure(): T $callback
	 * @return T
	 */
	public static function measure(string $type, string $key, \Closure $callback): mixed
	{
		if (!self::$active) {
			return $callback();
		}

		$start = (int) \hrtime(true);
		$error = true;

		try {
			$result = $callback();
			$error = false;

			return $result;
		} finally {
			self::record($type, $key, (int) \hrtime(true) - $start, $error, $start);
		}
	}

	/**
	 * Zaznamená jednu hotovou operaci. `$startNs` = hrtime začátku, když ho volající zná (jinak se dopočítá).
	 */
	public static function record(string $type, string $key, int $durationNs, bool $error = false, ?int $startNs = null): void
	{
		if (!self::$active) {
			return;
		}

		if (isset(self::$keys[$type][$key])) {
			$stats = &self::$keys[$type][$key];
			$stats[0]++;
			$stats[1] += $durationNs;

			if ($durationNs > $stats[2]) {
				$stats[2] = $durationNs;
			}

			if ($error) {
				$stats[3]++;
			}

			unset($stats);
		} else {
			if (isset(self::$keys[$type]) && \count(self::$keys[$type]) >= self::$maxKeys) {
				$key = self::KEY_OVERFLOW;
			}

			if (isset(self::$keys[$type][$key])) {
				self::record($type, $key, $durationNs, $error, $startNs);

				return;
			}

			self::$keys[$type][$key] = [1, $durationNs, $durationNs, $error ? 1 : 0];
		}

		if (self::$spanCount < self::$maxSpans) {
			$startNs ??= (int) \hrtime(true) - $durationNs;
			self::$spans[] = [$type, $startNs - self::$hrStart, $durationNs, $key];
			self::$spanCount++;
		} else {
			self::$droppedSpans++;
		}

		if ($durationNs < self::$slowNs) {
			return;
		}

		self::recordSlow($type, $key, $durationNs, $error);
	}

	/**
	 * Voláno z register_shutdown_function — dojede request, který host neukončil flush()em.
	 */
	public static function flushOnShutdown(): void
	{
		if (!self::$active) {
			return;
		}

		$last = \error_get_last();

		if ($last !== null && ($last['type'] & (\E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR)) !== 0) {
			self::markError('fatal');
		}

		self::flush();
	}

	/**
	 * Uzavře request a odešle datagram. Po flush() je recorder neaktivní až do dalšího start().
	 */
	public static function flush(?int $status = null): void
	{
		if (!self::$active) {
			return;
		}

		self::$active = false;

		try {
			$json = self::encode(self::buildPayload($status));

			if ($json !== null && self::$transport !== null) {
				self::$transport->send($json);
			}
		} catch (\Throwable) {
			// telemetrie nikdy nerozbije request
		} finally {
			self::reset();
		}
	}

	/**
	 * Sestaví datagram (bez odeslání). Veřejné kvůli testům.
	 * @return array<string, mixed>
	 */
	public static function buildPayload(?int $status = null): array
	{
		$buildStart = (int) \hrtime(true);
		$config = self::$config ?? new TelemetryConfig();
		$elapsedMs = ($buildStart - self::$hrStart) / 1000000;
		$totalMs = $elapsedMs + (self::$bootMs ?? 0.0);

		if ($status === null) {
			$code = \http_response_code();
			$status = \is_int($code) ? $code : null;
		}

		$sampled = self::$error !== null
			|| ($status !== null && $status >= 500)
			|| $totalMs >= $config->slowRequestMs
			|| ($config->sampleRate > 0 && \mt_rand() / \mt_getrandmax() < $config->sampleRate);

		[$aggregates, $keys, $texts, $index] = self::buildKeys();

		$payload = [
			'v' => self::PROTOCOL_VERSION,
			'k' => \PHP_SAPI === 'cli' ? 'cli' : 'req',
			'env' => $config->environment,
			'ts' => \round(self::$requestStart, 3),
			'route' => self::$route ?? self::defaultRoute(),
			'm' => \is_string($method = self::server('REQUEST_METHOD')) ? $method : null,
			'st' => $status,
			'ms' => \round($totalMs, 3),
			'mem' => \round(\memory_get_peak_usage() / 1048576, 1),
			'ph' => self::buildPhases($buildStart),
			'proc' => self::buildProcess(),
			'tags' => self::$tags === [] ? null : self::$tags,
			'err' => self::$error,
			'agg' => $aggregates,
			'keys' => $keys,
			'tx' => $texts === [] ? null : $texts,
			'slow' => self::$slow === [] ? null : self::buildSlow($index),
			'spans' => $sampled ? self::buildSpans($index) : null,
			'ds' => self::$droppedSpans,
			'smp' => $sampled,
		];

		$payload['ovh'] = \round(((int) \hrtime(true) - $buildStart) / 1000, 1);

		return $payload;
	}

	/**
	 * Jen pro testy a benchmarky.
	 */
	public static function reset(): void
	{
		self::$active = false;
		self::$config = null;
		self::$transport = null;
		self::$hrStart = 0;
		self::$requestStart = 0.0;
		self::$bootMs = null;
		self::$phases = [];
		self::$route = null;
		self::$error = null;
		self::$tags = [];
		self::$keys = [];
		self::$spans = [];
		self::$spanCount = 0;
		self::$droppedSpans = 0;
		self::$slow = [];
		self::$backtraces = 0;
		self::$opcacheStart = null;
	}

	/**
	 * Zkrácení datagramu pod `maxPayloadBytes`: nejdřív spany, pak texty a pomalé operace, pak klíče.
	 * @param array<string, mixed> $payload
	 */
	private static function encode(array $payload): ?string
	{
		$flags = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE;
		$limit = self::$config !== null ? self::$config->maxPayloadBytes : 60000;

		foreach ([[], ['spans'], ['tx', 'slow'], ['keys']] as $drop) {
			foreach ($drop as $field) {
				$payload[$field] = null;
				$payload['trunc'] = true;
			}

			$json = \json_encode($payload, $flags);

			if ($json !== false && \mb_strlen($json, '8bit') <= $limit) {
				return $json;
			}
		}

		return null;
	}

	/**
	 * Klíče jdou jako seznam `[ref, počet, součet ms, max ms, chyby]`; spany a pomalé operace na ně
	 * odkazují indexem, aby se text klíče (SQL) v datagramu neopakoval.
	 * @return array{
	 *     array<string, array{int, float, float, int}>,
	 *     array<string, list<array{string, int, float, float, int}>>,
	 *     array<string, string>,
	 *     array<string, array<string, int>>
	 * }
	 */
	private static function buildKeys(): array
	{
		$aggregates = [];
		$keys = [];
		$texts = [];
		$index = [];

		foreach (self::$keys as $type => $byKey) {
			$count = 0;
			$sum = 0;
			$max = 0;
			$errors = 0;
			$hashed = [];
			$keys[$type] = [];

			foreach ($byKey as $key => $stats) {
				$key = (string) $key;
				$count += $stats[0];
				$sum += $stats[1];
				$max = \max($max, $stats[2]);
				$errors += $stats[3];
				$ref = $key;

				if (\mb_strlen($key, '8bit') > self::LONG_KEY) {
					$ref = 'h:' . \hash('crc32b', $key);
					$hashed[$ref] = [$stats[1], $key];
				}

				$index[$type][$key] = \count($keys[$type]);
				$keys[$type][] = [$ref, $stats[0], self::ms($stats[1]), self::ms($stats[2]), $stats[3]];
			}

			\uasort($hashed, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

			foreach (\array_slice($hashed, 0, self::TEXTS_PER_TYPE, true) as $ref => [, $text]) {
				$texts[$ref] = \mb_substr($text, 0, self::TEXT_LIMIT);
			}

			$aggregates[$type] = [$count, self::ms($sum), self::ms($max), $errors];
		}

		return [$aggregates, $keys, $texts, $index];
	}

	/**
	 * @return array<string, float>
	 */
	private static function buildPhases(int $end): array
	{
		$phases = [];

		if (self::$bootMs !== null) {
			$phases['boot'] = \round(self::$bootMs, 3);
		}

		$marks = self::$phases;
		\asort($marks);
		$names = \array_keys($marks);

		foreach ($names as $i => $name) {
			$next = isset($names[$i + 1]) ? $marks[$names[$i + 1]] : $end;
			$phases[$name] = self::ms($next - $marks[$name]);
		}

		return $phases;
	}

	/**
	 * @return array{pid: int|false, age: float|null, comp: int|null, cold: bool|null}
	 */
	private static function buildProcess(): array
	{
		$now = OpcacheProbe::read();
		$compiled = $now !== null && self::$opcacheStart !== null ? \max(0, $now[0] - self::$opcacheStart[0]) : null;

		return [
			'pid' => \getmypid(),
			'age' => $now !== null && $now[1] > 0 ? \round(\microtime(true) - $now[1], 1) : null,
			'comp' => $compiled,
			'cold' => $compiled !== null ? $compiled > 0 : null,
		];
	}

	/**
	 * @param array<string, array<string, int>> $index
	 * @return list<array{t: string, i: int, ms: float, err: bool, bt?: list<string>}>
	 */
	private static function buildSlow(array $index): array
	{
		$slow = [];

		foreach (self::$slow as $item) {
			$entry = ['t' => $item['t'], 'i' => $index[$item['t']][$item['k']] ?? -1, 'ms' => $item['ms'], 'err' => $item['err']];

			if (isset($item['bt'])) {
				$entry['bt'] = $item['bt'];
			}

			$slow[] = $entry;
		}

		return $slow;
	}

	/**
	 * @param array<string, array<string, int>> $index
	 * @return list<array{string, int, int, int}> [typ, index klíče, start µs, doba µs]
	 */
	private static function buildSpans(array $index): array
	{
		$spans = [];

		foreach (self::$spans as [$type, $start, $duration, $key]) {
			$spans[] = [$type, $index[$type][$key] ?? -1, \intdiv($start, 1000), \intdiv($duration, 1000)];
		}

		return $spans;
	}

	private static function recordSlow(string $type, string $key, int $durationNs, bool $error): void
	{
		$config = self::$config;

		if ($config === null || \count(self::$slow) >= $config->maxSlowSpans) {
			return;
		}

		$item = ['t' => $type, 'k' => $key, 'ms' => self::ms($durationNs), 'err' => $error];

		if (self::$backtraces < $config->maxBacktraces) {
			self::$backtraces++;
			$item['bt'] = self::backtrace();
		}

		self::$slow[] = $item;
	}

	/**
	 * @return list<string>
	 */
	private static function backtrace(): array
	{
		$frames = [];

		foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_FRAMES + 8) as $frame) {
			$file = $frame['file'] ?? null;

			if ($file === null || \str_starts_with($file, __DIR__)) {
				continue;
			}

			$frames[] = $file . ':' . ($frame['line'] ?? 0);

			if (\count($frames) >= self::BACKTRACE_FRAMES) {
				break;
			}
		}

		return $frames;
	}

	private static function defaultRoute(): string
	{
		if (\PHP_SAPI === 'cli') {
			$argv = self::server('argv') ?? [];

			return \is_array($argv) ? \implode(' ', \array_map('strval', \array_slice($argv, 0, 2))) : 'cli';
		}

		return '(unrouted)';
	}

	/**
	 * Recorder nemá DI (volá se z háčků hluboko v kódu a z shutdown funkce), proto čte `$_SERVER` přímo.
	 */
	private static function server(string $name): mixed
	{
		// phpcs:ignore
		return $_SERVER[$name] ?? null;
	}

	private static function ms(int $nanoseconds): float
	{
		return \round($nanoseconds / 1000000, 3);
	}
}
