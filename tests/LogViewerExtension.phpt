<?php

declare(strict_types=1);

use LiquidMonitorConnector\Bridges\LiquidMonitorLogViewerDI;
use Nette\Application\IPresenterFactory;
use Nette\Application\PresenterFactory;
use Nette\Application\Routers\RouteList;
use Nette\DI\Compiler;
use Nette\Routing\Router;
use Nette\Schema\Processor;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// addSetup() wraps the call as [Reference(self), 'method'] — pull out the method name.
$method = static fn ($setup): ?string => \is_array($setup->getEntity()) ? ($setup->getEntity()[1] ?? null) : $setup->getEntity();

// Bundled package must be present — the connector reuses its presenters/LogReader.
Assert::true(\class_exists(\LogViewer\LogViewerApiPresenter::class));
Assert::true(\class_exists(\LogViewer\LogViewerPresenter::class));

$boot = static function (array $rawConfig): LiquidMonitorLogViewerDI {
	$extension = new LiquidMonitorLogViewerDI();
	$extension->setCompiler(new Compiler(), 'liquidMonitorLogViewer');
	$extension->setConfig((new Processor())->process($extension->getConfigSchema(), $rawConfig));

	return $extension;
};

// --- Default config: registers the JSON API + UI routes for the bundled presenters. ---
$extension = $boot([]);
$extension->loadConfiguration();

$builder = $extension->getContainerBuilder();
$routes = $builder->getDefinition('liquidMonitorLogViewer.routes');

Assert::same(RouteList::class, $routes->getType());
Assert::false($routes->isAutowired()); // must not collide with the app router during type resolution
Assert::count(4, $routes->getSetup()); // list/api + view + download + UI catch-all

$apiRoute = $routes->getSetup()[0];
Assert::same('addRoute', $method($apiRoute));
Assert::same('log-viewer/api/<action>', $apiRoute->arguments[0]);
Assert::same('LogViewer:LogViewerApi:default', $apiRoute->arguments[1]);

// --- beforeCompile wires the presenter mapping + prepends the route list. ---
$builder->addDefinition('router')->setType(RouteList::class); // app router stub (autowired)
$builder->addDefinition('presenterFactory')->setType(PresenterFactory::class);

$extension->beforeCompile();

$mappingSetups = \array_filter(
	$builder->getDefinitionByType(IPresenterFactory::class)->getSetup(),
	static fn ($setup): bool => $method($setup) === 'setMapping',
);
Assert::count(1, $mappingSetups);
Assert::same(['LogViewer' => 'LogViewer\\*Presenter'], \reset($mappingSetups)->arguments[0]);

$prependSetups = \array_filter(
	$builder->getDefinitionByType(Router::class)->getSetup(),
	static fn ($setup): bool => $method($setup) === 'prepend',
);
Assert::count(1, $prependSetups);

// --- registerRoutes:false is a full opt-out. ---
$disabled = $boot(['registerRoutes' => false]);
$disabled->loadConfiguration();
Assert::false($disabled->getContainerBuilder()->hasDefinition('liquidMonitorLogViewer.routes'));

echo "\nOK " . __FILE__ . "\n";
