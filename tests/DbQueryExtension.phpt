<?php

declare(strict_types=1);

use LiquidMonitorConnector\Bridges\LiquidMonitorDbQueryDI;
use LiquidMonitorConnector\DbQuery\DbQueryConfig;
use Nette\Application\IPresenterFactory;
use Nette\Application\PresenterFactory;
use Nette\Application\Routers\RouteList;
use Nette\DI\Compiler;
use Nette\Routing\Router;
use Nette\Schema\Processor;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$method = static fn ($setup): ?string => \is_array($setup->getEntity()) ? ($setup->getEntity()[1] ?? null) : $setup->getEntity();

Assert::true(\class_exists(\LiquidMonitorConnector\DbQuery\DbQueryApiPresenter::class));

$boot = static function (array $rawConfig): LiquidMonitorDbQueryDI {
	$extension = new LiquidMonitorDbQueryDI();
	$extension->setCompiler(new Compiler(), 'liquidMonitorDbQuery');
	$extension->setConfig((new Processor())->process($extension->getConfigSchema(), $rawConfig));

	return $extension;
};

// --- Default config: registers the JSON API route for the bundled presenter. ---
$extension = $boot([]);
$extension->loadConfiguration();

$builder = $extension->getContainerBuilder();

$configDef = $builder->getDefinition('liquidMonitorDbQuery.config');
Assert::same(DbQueryConfig::class, $configDef->getFactory()->getEntity());

$routes = $builder->getDefinition('liquidMonitorDbQuery.routes');
Assert::same(RouteList::class, $routes->getType());
Assert::false($routes->isAutowired());
Assert::count(1, $routes->getSetup());

$queryRoute = $routes->getSetup()[0];
Assert::same('addRoute', $method($queryRoute));
Assert::same('db-query/api/query', $queryRoute->arguments[0]);
Assert::same('DbQuery:DbQueryApi:query', $queryRoute->arguments[1]);

// --- beforeCompile wires the presenter mapping + prepends the route list. ---
$builder->addDefinition('router')->setType(RouteList::class);
$builder->addDefinition('presenterFactory')->setType(PresenterFactory::class);

$extension->beforeCompile();

$mappingSetups = \array_filter(
	$builder->getDefinitionByType(IPresenterFactory::class)->getSetup(),
	static fn ($setup): bool => $method($setup) === 'setMapping',
);
Assert::count(1, $mappingSetups);
Assert::same(['DbQuery' => 'LiquidMonitorConnector\\DbQuery\\*Presenter'], \reset($mappingSetups)->arguments[0]);

$prependSetups = \array_filter(
	$builder->getDefinitionByType(Router::class)->getSetup(),
	static fn ($setup): bool => $method($setup) === 'prepend',
);
Assert::count(1, $prependSetups);

// --- apiToken is passed into DbQueryConfig. ---
$withToken = $boot(['apiToken' => 'secret-token']);
$withToken->loadConfiguration();
$tokenConfig = $withToken->getContainerBuilder()->getDefinition('liquidMonitorDbQuery.config');
Assert::same(['apiToken' => 'secret-token'], $tokenConfig->getFactory()->arguments);

// --- registerRoutes:false is a full opt-out. ---
$disabled = $boot(['registerRoutes' => false]);
$disabled->loadConfiguration();
Assert::false($disabled->getContainerBuilder()->hasDefinition('liquidMonitorDbQuery.routes'));
Assert::true($disabled->getContainerBuilder()->hasDefinition('liquidMonitorDbQuery.config'));

echo "\nOK " . __FILE__ . "\n";
