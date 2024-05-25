<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Bridges;

use LiquidMonitorConnector\LiquidMonitorLogger;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\MissingServiceException;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Tracy\ILogger;

class LiquidMonitorLoggerDI extends \Nette\DI\CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'title' => Expect::string(),
			'freezeInterval' => Expect::string('24 hours'),
			'levels' => Expect::array([ILogger::ERROR, ILogger::EXCEPTION, ILogger::CRITICAL, ILogger::WARNING, ILogger::INFO]),
			'omitExceptions' => Expect::array([]),
		]);
	}
	
	public function loadConfiguration(): void
	{
		/** @var \stdClass $config */
		$config = $this->getConfig();
		
		$builder = $this->getContainerBuilder();

		if (!$builder->hasDefinition('liquidMonitorConnector')) {
			throw new MissingServiceException('LiquidMonitorLogger: LiquidMonitorConnector extension is not registered.');
		};
		
		$builder->removeDefinition('tracy.logger');
		
		$builder->addDefinition('tracy.logger', new ServiceDefinition())
			->setType(LiquidMonitorLogger::class)
			->addSetup('setProperties', [
				$config->title,
				$config->levels,
			]);
	}
}
