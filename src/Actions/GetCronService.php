<?php

namespace LiquidMonitorConnector\Actions;

use Base\BaseAction;
use LiquidMonitorConnector\LiquidMonitorConnector;
use Nette\DI\Container;

class GetCronService extends BaseAction
{
	public function __construct(private readonly Container $container)
	{
	}

	public function execute(): LiquidMonitorConnector|null
	{
		return $this->getLocalCachedOutput('cron', function () {
			return $this->container->getByType(LiquidMonitorConnector::class, false);
		});
	}
}
