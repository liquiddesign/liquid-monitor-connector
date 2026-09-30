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
	 * Bez `$monitorUrl` / `$apiKey` agent zapisuje jen do JSONL v `$outDir`. Klíč jde přes prostředí
	 * (`LQDECK_API_KEY`), ne jako argument — příkazová řádka je v `ps` vidět všem uživatelům stroje.
	 */
	public static function command(
		TelemetryConfig $config,
		string $agentBin,
		string $outDir,
		string $phpBinary,
		?string $monitorUrl = null,
		?string $apiKey = null,
	): string {
		$send = $monitorUrl !== null && $apiKey !== null && $apiKey !== '';

		return \sprintf(
			'%s%s %s --host=%s --port=%d --max-runtime=%d --out-dir=%s%s',
			$send ? 'LQDECK_API_KEY=' . \escapeshellarg($apiKey) . ' ' : '',
			\escapeshellarg($phpBinary),
			\escapeshellarg($agentBin),
			\escapeshellarg($config->host),
			$config->port,
			$config->agentMaxRuntime,
			\escapeshellarg($outDir),
			$send ? ' --monitor-url=' . \escapeshellarg($monitorUrl) : '',
		);
	}

	public static function spawnDetached(
		TelemetryConfig $config,
		string $agentBin,
		string $outDir,
		string $phpBinary,
		?string $monitorUrl = null,
		?string $apiKey = null,
	): void {
		\exec('nohup ' . self::command($config, $agentBin, $outDir, $phpBinary, $monitorUrl, $apiKey) . ' > /dev/null 2>&1 &');
	}
}
