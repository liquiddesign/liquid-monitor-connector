<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Carbon\Carbon;
use LiquidMonitorConnector\Cron;
use LiquidMonitorConnector\Telemetry\Agent\AgentLauncher;
use LiquidMonitorConnector\Telemetry\TelemetryConfig;
use Nette\DI\Container;

/**
 * Spustí `vendor/bin/monitor-worker run` pro Nette host aplikace.
 *
 * URL a API klíč bere z {@see Cron} služby (`liquidMonitorConnector` v NEON).
 * Crunz volá {@see runNetteAuto()} přes `crunz/MonitorWorkerTasks.php` (host: jeden řádek require).
 * Bootstrap: `bin/monitor-worker-nette-bootstrap.php` v balíčku (`App\Bootstrap` default).
 */
final class MonitorWorkerLauncher
{
	/**
	 * @return int Process exit code (0 = OK).
	 */
	public static function runNetteAuto(
		int $maxRuntime = 55,
		?string $projectRoot = null,
		?string $phpBinary = null,
	): int {
		$bootstrapPath = MonitorWorkerBootstrap::vendorNetteBootstrapPath();

		return self::runNetteBootstrap($bootstrapPath, $maxRuntime, $projectRoot, $phpBinary);
	}

	/**
	 * @return int Process exit code (0 = OK).
	 */
	public static function runNetteBootstrap(
		string $netteBootstrapPath,
		int $maxRuntime = 55,
		?string $projectRoot = null,
		?string $phpBinary = null,
	): int {
		$projectRoot ??= MonitorWorkerBootstrap::resolveProjectRoot(\dirname($netteBootstrapPath));
		$workerBin = $projectRoot . '/vendor/bin/monitor-worker';

		if (!\is_file($workerBin)) {
			self::log('monitor-worker: vendor/bin/monitor-worker missing (connector 3.x+) — skip');

			return 0;
		}

		if (!\is_file($netteBootstrapPath)) {
			self::log("monitor-worker: bootstrap missing at {$netteBootstrapPath}");

			return 1;
		}

		/** @var mixed $container */
		$container = require $netteBootstrapPath;

		if (!$container instanceof Container) {
			self::log('monitor-worker: bootstrap must return Nette\DI\Container');

			return 1;
		}

		$phpBinary ??= (new \Symfony\Component\Process\PhpExecutableFinder())->find() ?: 'php';

		// Agent telemetrie nezávisí na cronech — běží, i když je connector (crony) vypnutý.
		self::startTelemetryAgent($container, $projectRoot, $phpBinary);

		try {
			$cron = $container->getByType(Cron::class);
		} catch (\Throwable $e) {
			self::log('monitor-worker: liquidMonitorConnector not configured — skip (' . $e->getMessage() . ')');

			return 0;
		}

		if (!$cron->isEnabled()) {
			self::log('monitor-worker: liquidMonitorConnector disabled — skip');

			return 0;
		}

		$apiKey = $cron->getApiKey();

		if ($apiKey === null || $apiKey === '') {
			self::log('monitor-worker: liquidMonitorConnector apiKey missing — skip');

			return 0;
		}

		$monitorUrl = ConnectorUrl::normalize($cron->getUrl());

		$cmd = \sprintf(
			'%s %s run --bootstrap=%s --monitor-url=%s --api-key=%s --max-runtime=%d 2>&1',
			\escapeshellarg($phpBinary),
			\escapeshellarg($workerBin),
			\escapeshellarg($netteBootstrapPath),
			\escapeshellarg($monitorUrl),
			\escapeshellarg($apiKey),
			$maxRuntime,
		);

		$output = [];
		$exitCode = 0;
		\exec($cmd, $output, $exitCode);

		if ($output !== []) {
			self::log(\implode("\n", $output));
		}

		if ($exitCode !== 0) {
			self::log("monitor-worker exit={$exitCode}");
		}

		return $exitCode;
	}

	private static function startTelemetryAgent(Container $container, string $projectRoot, string $phpBinary): void
	{
		try {
			$config = $container->getByType(TelemetryConfig::class, false);
		} catch (\Throwable) {
			return;
		}

		if (!$config instanceof TelemetryConfig || !$config->enabled || !$config->agentAutostart || $config->agentOutDir === null) {
			return;
		}

		$agentBin = $projectRoot . '/vendor/bin/monitor-telemetry-agent';

		if (!\is_file($agentBin)) {
			self::log('telemetry-agent: vendor/bin/monitor-telemetry-agent missing — skip');

			return;
		}

		$monitorUrl = $config->monitorUrl;
		$apiKey = $config->apiKey;

		if ($monitorUrl === null || $apiKey === null) {
			try {
				$cron = $container->getByType(Cron::class, false);
			} catch (\Throwable) {
				$cron = null;
			}

			if ($cron instanceof Cron && $cron->isEnabled()) {
				$monitorUrl ??= $cron->getUrl();
				$apiKey ??= $cron->getApiKey();
			}
		}

		AgentLauncher::spawnDetached(
			$config,
			$agentBin,
			$config->agentOutDir,
			$phpBinary,
			$monitorUrl !== null ? ConnectorUrl::normalize($monitorUrl) : null,
			$apiKey,
		);
	}

	private static function log(string $message): void
	{
		echo '[' . Carbon::now()->format('Y-m-d H:i:s') . '] ' . $message . "\n";
	}
}
