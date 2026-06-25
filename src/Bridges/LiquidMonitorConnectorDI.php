<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Bridges;

use LiquidMonitorConnector\Actions\GetCronService;
use LiquidMonitorConnector\Cron;
use LiquidMonitorConnector\ErrorReporter;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * Registrace v host aplikaci:
 *
 *   liquidMonitorConnector:
 *       url: https://monitor.example/api_connector # sdílený fallback (povinné)
 *       apiKey: SHARED_KEY
 *       enabled: true
 *
 * Crony a logy/chyby lze nasměrovat na různé instance monitoru. Volitelné
 * per-kanálové overrides (každý nese vlastní `url` i `apiKey`); cokoli, co se
 * vynechá, dědí z top-level `url`/`apiKey`:
 *
 *   liquidMonitorConnector:
 *       url: https://v1-monitor.example/api_connector # crony → starý monitor
 *       apiKey: KEY_V1
 *       log: # chyby/logy → nový monitor
 *           url: https://v2-monitor.example/api_connector
 *           apiKey: KEY_V2
 *
 * TLS ověření certifikátu monitoru je defaultně zapnuté (`verifyTls: true`). Vypnout
 * (nebo nasměrovat na vlastní CA bundle) jde jen kvůli lokálnímu dev se self-signed
 * certem — `verifyTls: false` otevírá MITM odposlech `apiKey`, takže na produkci
 * nikdy. Preferovaný způsob pro self-signed dev cert je cesta k CA bundlu:
 *
 *   liquidMonitorConnector:
 *       url: https://monitor.local/api_connector
 *       apiKey: KEY
 *       verifyTls: false # dev only! vypne ověření certifikátu
 *       # verifyTls: /etc/ssl/certs/dev-ca.pem # lepší: ověřovat proti vlastní CA
 */
class LiquidMonitorConnectorDI extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'url' => Expect::string()->required(),
			'apiKey' => Expect::string(null),
			'enabled' => Expect::bool(true),
			// TLS ověření certifikátu monitoru: true = ověřovat (default), false = vypnuto
			// (jen dev se self-signed certem), string = cesta k vlastnímu CA bundlu.
			'verifyTls' => Expect::anyOf(Expect::bool(), Expect::string())->default(true),
			'cron' => $this->channelSchema(),
			'log' => $this->channelSchema(),
		]);
	}

	public function loadConfiguration(): void
	{
		/** @var \stdClass $config */
		$config = $this->getConfig();

		$cronUrl = $config->cron['url'] ?? $config->url;
		$cronApiKey = $config->cron['apiKey'] ?? $config->apiKey;
		$logUrl = $config->log['url'] ?? $config->url;
		$logApiKey = $config->log['apiKey'] ?? $config->apiKey;

		$builder = $this->getContainerBuilder();

		$cron = $builder->addDefinition('liquidMonitorConnector')->setType(Cron::class);
		$cron->addSetup('setConfiguration', [$cronUrl, $cronApiKey, $config->enabled, $logUrl, $logApiKey, $config->verifyTls]);

		$builder->addDefinition('liquidMonitorConnector.getCronService')->setType(GetCronService::class);

		// Chybový/logový kanál jako samostatná služba — `LiquidMonitorLogger` na ni
		// míří místo na `Cron`, takže ho lze provozovat i bez cronů (viz LiquidMonitorLoggerDI).
		$builder->addDefinition('liquidMonitorConnector.errorReporter')
			->setType(ErrorReporter::class)
			->addSetup('setConfiguration', [$logUrl, $logApiKey, $config->enabled, $config->verifyTls]);
	}

	/**
	 * Schéma jednoho odchozího kanálu (crony / logy). Obě hodnoty jsou volitelné —
	 * null znamená fallback na top-level `url`/`apiKey`.
	 */
	private function channelSchema(): Schema
	{
		return Expect::structure([
			'url' => Expect::string()->nullable(),
			'apiKey' => Expect::string()->nullable(),
		])->castTo('array');
	}
}
