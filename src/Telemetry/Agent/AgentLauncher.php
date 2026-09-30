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
	public static function command(TelemetryConfig $config, string $agentBin, string $outDir, string $phpBinary): string
	{
		return \sprintf(
			'%s %s --host=%s --port=%d --max-runtime=%d --out-dir=%s',
			\escapeshellarg($phpBinary),
			\escapeshellarg($agentBin),
			\escapeshellarg($config->host),
			$config->port,
			$config->agentMaxRuntime,
			\escapeshellarg($outDir),
		);
	}

	public static function spawnDetached(TelemetryConfig $config, string $agentBin, string $outDir, string $phpBinary): void
	{
		\exec('nohup ' . self::command($config, $agentBin, $outDir, $phpBinary) . ' > /dev/null 2>&1 &');
	}
}
