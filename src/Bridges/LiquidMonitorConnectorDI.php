<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Bridges;

use LiquidMonitorConnector\Connector;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

class LiquidMonitorConnectorDI extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'url' => Expect::string()->required(),
			'apiKey' => Expect::string()->required(),
		]);
	}
	
	public function loadConfiguration(): void
	{
		/** @var \stdClass $config */
		$config = $this->getConfig();
		
		$builder = $this->getContainerBuilder();
		
		$connector = $builder->addDefinition('liquidMonitorConnector')->setType(Connector::class);
		$connector->addSetup('setConfiguration', [$config->url, $config->apiKey]);
	}
}
