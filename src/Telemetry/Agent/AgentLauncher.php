<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry\Agent;

use LiquidMonitorConnector\Telemetry\TelemetryConfig;

/**
 * Spustí agenta na pozadí (nečeká na něj). Volá ho {@see \LiquidMonitorConnector\Worker\MonitorWorkerLauncher}
 * každou minutu, takže projekty na pull modelu nepotřebují žádný nový Crunz task.
 */
final class AgentLauncher
{
	/**
	 * Shellový příkaz agenta. Bez `$monitorUrl` / `$apiKey` agent zapisuje jen do JSONL v `$outDir`;
	 * `$maxRuntime` přebije `agentMaxRuntime` (launcher ho natáhne na délku běhu workeru).
	 * Klíč jde přes prostředí (`LQDECK_API_KEY`), ne jako argument — příkazová řádka je v `ps` vidět
	 * všem uživatelům stroje.
	 */
	public static function command(
		TelemetryConfig $config,
		string $agentBin,
		string $outDir,
		string $phpBinary,
		?string $monitorUrl = null,
		?string $apiKey = null,
		?int $maxRuntime = null,
	): string {
		return self::environment($monitorUrl, $apiKey) . self::arguments($config, $agentBin, $outDir, $phpBinary, $monitorUrl, $apiKey, $maxRuntime);
	}

	/**
	 * Příkaz pro spuštění na pozadí. Přiřazení proměnné musí stát PŘED `nohup` — `nohup VAR=x cmd`
	 * by se pokusil spustit `VAR=x` jako program a agent s klíčem by nikdy nenaběhl (3.2.0).
	 */
	public static function detachedCommand(
		TelemetryConfig $config,
		string $agentBin,
		string $outDir,
		string $phpBinary,
		?string $monitorUrl = null,
		?string $apiKey = null,
		?int $maxRuntime = null,
	): string {
		return self::environment($monitorUrl, $apiKey)
			. 'nohup ' . self::arguments($config, $agentBin, $outDir, $phpBinary, $monitorUrl, $apiKey, $maxRuntime)
			. ' > /dev/null 2>&1 &';
	}

	public static function spawnDetached(
		TelemetryConfig $config,
		string $agentBin,
		string $outDir,
		string $phpBinary,
		?string $monitorUrl = null,
		?string $apiKey = null,
		?int $maxRuntime = null,
	): void {
		\exec(self::detachedCommand($config, $agentBin, $outDir, $phpBinary, $monitorUrl, $apiKey, $maxRuntime));
	}

	private static function environment(?string $monitorUrl, ?string $apiKey): string
	{
		return self::sends($monitorUrl, $apiKey) ? 'LQDECK_API_KEY=' . \escapeshellarg((string) $apiKey) . ' ' : '';
	}

	private static function arguments(
		TelemetryConfig $config,
		string $agentBin,
		string $outDir,
		string $phpBinary,
		?string $monitorUrl,
		?string $apiKey,
		?int $maxRuntime,
	): string {
		return \sprintf(
			'%s %s --host=%s --port=%d --max-runtime=%d --out-dir=%s%s',
			\escapeshellarg($phpBinary),
			\escapeshellarg($agentBin),
			\escapeshellarg($config->host),
			$config->port,
			$maxRuntime ?? $config->agentMaxRuntime,
			\escapeshellarg($outDir),
			self::sends($monitorUrl, $apiKey) ? ' --monitor-url=' . \escapeshellarg((string) $monitorUrl) : '',
		);
	}

	private static function sends(?string $monitorUrl, ?string $apiKey): bool
	{
		return $monitorUrl !== null && $apiKey !== null && $apiKey !== '';
	}
}
