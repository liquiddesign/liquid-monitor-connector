<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Bridges;

use LiquidMonitorConnector\Actions\GetCronService;
use LiquidMonitorConnector\Cron;
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
 */
class LiquidMonitorConnectorDI extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'url' => Expect::string()->required(),
			'apiKey' => Expect::string(null),
			'enabled' => Expect::bool(true),
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
		$cron->addSetup('setConfiguration', [$cronUrl, $cronApiKey, $config->enabled, $logUrl, $logApiKey]);

		$builder->addDefinition('liquidMonitorConnector.getCronService')->setType(GetCronService::class);
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
