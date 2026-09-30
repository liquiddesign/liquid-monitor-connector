<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry;

/**
 * Nastavení výkonové telemetrie (NEON sekce `liquidMonitorTelemetry`, viz {@see \LiquidMonitorConnector\Bridges\LiquidMonitorTelemetryDI}).
 *
 * Výchozí stav je vypnuto — upgrade connectoru nikomu nezmění chování bez vědomí.
 */
final class TelemetryConfig
{
	public const DEFAULT_PORT = 47801;

	public function __construct(
		public readonly bool $enabled = false,
		public readonly ?string $environment = null,
		public readonly string $host = '127.0.0.1',
		public readonly int $port = self::DEFAULT_PORT,
		/** Podíl requestů, u kterých se posílá celá časová osa (0–1). Pomalé a chybové jdou vždy. */
		public readonly float $sampleRate = 0.1,
		/** Request pomalejší než tohle jde vždy s časovou osou. */
		public readonly float $slowRequestMs = 1000.0,
		/** Operace (SQL, HTTP, …) pomalejší než tohle se zapíše do „slow" i s textem a backtrace. */
		public readonly float $slowSpanMs = 100.0,
		public readonly int $maxSpans = 300,
		public readonly int $maxKeysPerType = 200,
		public readonly int $maxSlowSpans = 20,
		public readonly int $maxBacktraces = 5,
		/** Strop datagramu; nad ním se zahodí nejdřív spany, pak texty. */
		public readonly int $maxPayloadBytes = 60000,
		/** Měřit i CLI procesy (crony, joby). Zatím jen pro ladění, CLI nemá fáze requestu. */
		public readonly bool $cli = false,
		public readonly bool $storm = true,
		public readonly bool $agentAutostart = true,
		public readonly ?string $agentOutDir = null,
		public readonly int $agentMaxRuntime = 65,
	) {
	}

	public function isActiveForCurrentSapi(): bool
	{
		return $this->enabled && ($this->cli || \PHP_SAPI !== 'cli');
	}
}
