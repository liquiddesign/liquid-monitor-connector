<?php

namespace LiquidMonitorConnector\Actions;

use Base\BaseAction;
use LiquidMonitorConnector\Cron;
use Nette\DI\Container;

class GetCronService extends BaseAction
{
	public function __construct(private readonly Container $container)
	{
	}

	public function execute(): Cron|null
	{
		return $this->getLocalCachedOutput('cron', function () {
			return $this->container->getByType(Cron::class, false);
		});
	}
}