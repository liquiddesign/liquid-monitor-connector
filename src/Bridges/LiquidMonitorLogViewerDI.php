<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Bridges;

use Nette\Application\IPresenterFactory;
use Nette\Application\Routers\RouteList;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Routing\Router;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * Vystavuje read-only JSON API pro Tracy logy (list/stat/view/search/download)
 * přímo z connectoru, identické s balíčkem liquiddesign/nette-log-viewer.
 *
 * Veškerá logika čtení logů a serializace žije v presenterech balíčku
 * (LogViewer\LogViewerApiPresenter / LogViewer\LogViewerPresenter) — tato
 * extension jen mirroruje DI wiring LogViewer\DI\LogViewerExtension, který je
 * `final`, a proto ho nelze přímo subclassovat. Přístup je gatovaný stejně
 * jako v balíčku přes Tracy debug mode (Debugger::isEnabled()) ve startupu
 * presenterů; žádná další autentizace se zde nepřidává.
 *
 * Registrace v host aplikaci:
 *
 *   extensions:
 *       liquidMonitorLogViewer: LiquidMonitorConnector\Bridges\LiquidMonitorLogViewerDI
 *
 * Volitelná konfigurace (stejná jako u nette-log-viewer):
 *
 *   liquidMonitorLogViewer:
 *       urlPrefix: log-viewer # URL prefix (bez úvodního lomítka)
 *       presenter: LogViewer:LogViewer # UI presenter
 *       apiPresenter: LogViewer:LogViewerApi # JSON API presenter
 *       registerRoutes: true # false = routy si spravuje host sám
 *       registerPresenterMapping: true # false = host má vlastní mapping
 */
class LiquidMonitorLogViewerDI extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'urlPrefix' => Expect::string('log-viewer'),
			'presenter' => Expect::string('LogViewer:LogViewer'),
			'apiPresenter' => Expect::string('LogViewer:LogViewerApi'),
			'registerRoutes' => Expect::bool(true),
			'registerPresenterMapping' => Expect::bool(true),
		])->castTo('array');
	}

	public function loadConfiguration(): void
	{
		/** @var array{urlPrefix: string, presenter: string, apiPresenter: string, registerRoutes: bool, registerPresenterMapping: bool} $config */
		$config = $this->config;

		if (!$config['registerRoutes']) {
			return;
		}

		$builder = $this->getContainerBuilder();
		$prefix = \rtrim($config['urlPrefix'], '/');
		$uiPresenter = $config['presenter'];
		$apiPresenter = $config['apiPresenter'];

		$builder->addDefinition($this->prefix('routes'))
			->setType(RouteList::class)
			->setFactory(RouteList::class)
			->addSetup('addRoute', ["{$prefix}/api/<action>", "{$apiPresenter}:default"])
			->addSetup('addRoute', ["{$prefix}/view/<file .+>", "{$uiPresenter}:view"])
			->addSetup('addRoute', ["{$prefix}/download/<file .+>", "{$uiPresenter}:download"])
			->addSetup('addRoute', ["{$prefix}[/<path .+>]", "{$uiPresenter}:default"])
			->setAutowired(false);
	}

	public function beforeCompile(): void
	{
		/** @var array{urlPrefix: string, presenter: string, apiPresenter: string, registerRoutes: bool, registerPresenterMapping: bool} $config */
		$config = $this->config;
		$builder = $this->getContainerBuilder();

		if ($config['registerPresenterMapping']) {
			$factory = $builder->getDefinitionByType(IPresenterFactory::class);
			\assert($factory instanceof ServiceDefinition);
			$factory->addSetup('setMapping', [['LogViewer' => 'LogViewer\\*Presenter']]);
		}

		if (!$config['registerRoutes']) {
			return;
		}

		$router = $builder->getDefinitionByType(Router::class);
		\assert($router instanceof ServiceDefinition);
		$router->addSetup('prepend', ['@' . $this->prefix('routes')]);
	}
}
