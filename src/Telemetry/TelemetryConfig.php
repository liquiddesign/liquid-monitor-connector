<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry;

/**
 * Nastavení výkonové telemetrie (NEON sekce `liquidMonitorTelemetry`, viz {@see \LiquidMonitorConnector\Bridges\LiquidMonitorTelemetryDI}).
 *
 * Výchozí stav je vypnuto — upgrade connectoru nikomu nezmění chování bez vědomí.
 *
 * Vlastnosti jsou kvůli PHP 8.0 bez `readonly` — po sestavení je nepřepisovat.
 * @immutable
 */
final class TelemetryConfig
{
	public const DEFAULT_PORT = 47801;

	public function __construct(
		public bool $enabled = false,
		public ?string $environment = null,
		public string $host = '127.0.0.1',
		public int $port = self::DEFAULT_PORT,
		/** Podíl requestů, u kterých se posílá celá časová osa (0–1). Pomalé a chybové jdou vždy. */
		public float $sampleRate = 0.1,
		/** Request pomalejší než tohle jde vždy s časovou osou. */
		public float $slowRequestMs = 1000.0,
		/** Operace (SQL, HTTP, …) pomalejší než tohle se zapíše do „slow" i s textem a backtrace. */
		public float $slowSpanMs = 100.0,
		public int $maxSpans = 300,
		public int $maxKeysPerType = 200,
		public int $maxSlowSpans = 20,
		public int $maxBacktraces = 5,
		/** Strop datagramu; nad ním se zahodí nejdřív spany, pak texty. */
		public int $maxPayloadBytes = 60000,
		/** Měřit i CLI procesy (crony, joby). Zatím jen pro ladění, CLI nemá fáze requestu. */
		public bool $cli = false,
		public bool $storm = true,
		public bool $agentAutostart = true,
		public ?string $agentOutDir = null,
		public int $agentMaxRuntime = 65,
		/** Kam agent posílá data; null = connector URL a klíč cronové extension (`liquidMonitorConnector`). */
		public ?string $monitorUrl = null,
		public ?string $apiKey = null,
	) {
	}

	public function isActiveForCurrentSapi(): bool
	{
		return $this->enabled && ($this->cli || \PHP_SAPI !== 'cli');
	}
}
