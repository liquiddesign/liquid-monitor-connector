<?php

namespace LiquidMonitorConnector\Actions;

use LiquidMonitorConnector\Cron;
use Nette\DI\Container;

/**
 * Líně dohledá službu Cron v DI kontejneru hosta (null, když connector není zaregistrovaný).
 *
 * Dřív dědila od Base\BaseAction jen kvůli getLocalCachedOutput(); kvůli jedné cache
 * tahala celé liquiddesign/base (→ StORM 2), což blokovalo hosty na StORM 1.
 * Cache se chová stejně jako dřív: nalezená služba se pamatuje, null ne.
 */
class GetCronService
{
	private Cron|null $cron = null;

	public function __construct(private readonly Container $container)
	{
	}

	public function execute(): Cron|null
	{
		return $this->cron ??= $this->container->getByType(Cron::class, false);
	}

	public function __invoke(): Cron|null
	{
		return $this->execute();
	}
}
