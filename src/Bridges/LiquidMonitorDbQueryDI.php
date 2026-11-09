<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Bridges;

use LiquidMonitorConnector\DbQuery\DbQueryConfig;
use Nette\Application\IPresenterFactory;
use Nette\Application\Routers\RouteList;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Routing\Router;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * Vystavuje read-only JSON API pro SQL dotazy proti databázi host aplikace.
 * Monitor posílá SQL + connection credentials v HTTP body; connector se připojí
 * přes PDO a vrátí výsledek jako flat JSON (columns, rows, …).
 *
 * Registrace v host aplikaci:
 *
 *   extensions:
 *       liquidMonitorDbQuery: LiquidMonitorConnector\Bridges\LiquidMonitorDbQueryDI
 *
 * Volitelná konfigurace:
 *
 *   liquidMonitorDbQuery:
 *       urlPrefix: db-query
 *       apiPresenter: DbQuery:DbQueryApi
 *       registerRoutes: true
 *       registerPresenterMapping: true
 *       apiToken: '…' # volitelné — vyžaduje X-Api-Key header
 *       allowedHosts: [127.0.0.1, localhost, '::1'] # výchozí — kam se smí připojit (DB host aplikace)
 */
class LiquidMonitorDbQueryDI extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'urlPrefix' => Expect::string('db-query'),
			'apiPresenter' => Expect::string('DbQuery:DbQueryApi'),
			'apiToken' => Expect::string()->nullable(),
			// null = loopback default; a list replaces it (Expect default lists would merge with it).
			'allowedHosts' => Expect::listOf('string')->nullable()->default(null),
			'registerRoutes' => Expect::bool(true),
			'registerPresenterMapping' => Expect::bool(true),
		])->castTo('array');
	}

	public function loadConfiguration(): void
	{
		/** @var array{urlPrefix: string, apiPresenter: string, apiToken: ?string, allowedHosts: list<string>|null, registerRoutes: bool, registerPresenterMapping: bool} $config */
		$config = $this->config;

		$builder = $this->getContainerBuilder();

		$builder->addDefinition($this->prefix('config'))
			->setFactory(DbQueryConfig::class, ['apiToken' => $config['apiToken'], 'allowedHosts' => $config['allowedHosts'] ?? DbQueryConfig::DEFAULT_ALLOWED_HOSTS]);

		if (!$config['registerRoutes']) {
			return;
		}

		$prefix = \rtrim($config['urlPrefix'], '/');
		$apiPresenter = $config['apiPresenter'];

		$builder->addDefinition($this->prefix('routes'))
			->setType(RouteList::class)
			->setFactory(RouteList::class)
			->addSetup('addRoute', ["{$prefix}/api/query", "{$apiPresenter}:query"])
			->setAutowired(false);
	}

	public function beforeCompile(): void
	{
		/** @var array{urlPrefix: string, apiPresenter: string, apiToken: ?string, allowedHosts: list<string>|null, registerRoutes: bool, registerPresenterMapping: bool} $config */
		$config = $this->config;
		$builder = $this->getContainerBuilder();

		if ($config['registerPresenterMapping']) {
			$factory = $builder->getDefinitionByType(IPresenterFactory::class);
			\assert($factory instanceof ServiceDefinition);
			$factory->addSetup('setMapping', [['DbQuery' => 'LiquidMonitorConnector\\DbQuery\\*Presenter']]);
		}

		if (!$config['registerRoutes']) {
			return;
		}

		$router = $builder->getDefinitionByType(Router::class);
		\assert($router instanceof ServiceDefinition);
		$router->addSetup('prepend', ['@' . $this->prefix('routes')]);
	}
}
