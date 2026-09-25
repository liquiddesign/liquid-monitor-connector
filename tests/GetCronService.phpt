<?php

declare(strict_types=1);

use LiquidMonitorConnector\Actions\GetCronService;
use LiquidMonitorConnector\Cron;
use Nette\DI\Container;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// GetCronService used to extend Base\BaseAction (liquiddesign/base → StORM 2). Standalone now —
// it must keep BaseAction's caching contract: a found service is looked up once, null is not cached.
Assert::false(\get_parent_class(GetCronService::class));

$container = new class extends Container {
	public int $lookups = 0;

	public object|null $cron = null;

	public function getByType(string $type, bool $throw = true): object|null
	{
		$this->lookups++;

		return $type === Cron::class ? $this->cron : null;
	}
};

$service = new GetCronService($container);

Assert::null($service->execute());
Assert::null($service->execute());
Assert::same(2, $container->lookups);

$container->cron = new Cron(new Request(new UrlScript('http://localhost/')));

Assert::same($container->cron, $service->execute());
Assert::same($container->cron, $service->execute());
Assert::same($container->cron, $service());
Assert::same(3, $container->lookups);
