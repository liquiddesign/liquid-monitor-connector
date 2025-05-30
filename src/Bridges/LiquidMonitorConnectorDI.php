<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Bridges;

use LiquidMonitorConnector\Actions\GetCronService;
use LiquidMonitorConnector\Cron;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

class LiquidMonitorConnectorDI extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'url' => Expect::string()->required(),
			'apiKey' => Expect::string(null),
			'enabled' => Expect::bool(true),
		]);
	}
	
	public function loadConfiguration(): void
	{
		/** @var \stdClass $config */
		$config = $this->getConfig();
		
		$builder = $this->getContainerBuilder();
		
		$cron = $builder->addDefinition('liquidMonitorConnector')->setType(Cron::class);
		$cron->addSetup('setConfiguration', [$config->url, $config->apiKey, $config->enabled]);

		$builder->addDefinition('liquidMonitorConnector.getCronService')->setType(GetCronService::class);
	}
}
