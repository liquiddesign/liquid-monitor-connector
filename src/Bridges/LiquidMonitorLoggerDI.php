<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Bridges;

use LiquidMonitorConnector\ErrorReporter;
use LiquidMonitorConnector\LiquidMonitorLogger;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\MissingServiceException;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Tracy\ILogger;

/**
 * Registrace v host aplikaci. Dvě varianty:
 *
 * 1) Spolu s cronovou extension (`liquidMonitorConnector`) — logger reusuje její
 *    chybový kanál (`liquidMonitorConnector.errorReporter`), `url`/`apiKey` níže
 *    se vynechají:
 *
 *      extensions:
 *          liquidMonitorConnector: LiquidMonitorConnector\Bridges\LiquidMonitorConnectorDI
 *          liquidMonitorLogger: LiquidMonitorConnector\Bridges\LiquidMonitorLoggerDI
 *
 * 2) Samostatně (jen sběr chyb, bez cronů) — logger dostane vlastní `url`/`apiKey`
 *    a cronová extension se vůbec neregistruje:
 *
 *      extensions:
 *          liquidMonitorLogger: LiquidMonitorConnector\Bridges\LiquidMonitorLoggerDI
 *
 *      liquidMonitorLogger:
 *          url: https://monitor.example/api_connector
 *          apiKey: PROJECT_API_KEY
 */
class LiquidMonitorLoggerDI extends \Nette\DI\CompilerExtension
{
	private const CONNECTOR_REPORTER = 'liquidMonitorConnector.errorReporter';

	public function getConfigSchema(): Schema
	{
		/** @var \Nette\Schema\Elements\Type $levels */
		$levels = Expect::array([ILogger::ERROR, ILogger::EXCEPTION, ILogger::CRITICAL, ILogger::WARNING, ILogger::INFO]);

		return Expect::structure([
			// @deprecated — title is no longer sent to the backend; kept here so existing NEON configs with `title:` do not break
			'title' => Expect::string(),
			'levels' => $levels->mergeDefaults(false),
			// Standalone error-only setup (no liquidMonitorConnector). When the cron
			// extension is registered, its log channel is reused and these stay null.
			'url' => Expect::string()->nullable(),
			'apiKey' => Expect::string()->nullable(),
			'enabled' => Expect::bool(true),
			// TLS ověření certifikátu monitoru pro standalone režim: true = ověřovat
			// (default), false = vypnuto (jen dev), string = cesta k vlastnímu CA bundlu.
			'verifyTls' => Expect::anyOf(Expect::bool(), Expect::string())->default(true),
		]);
	}

	public function loadConfiguration(): void
	{
		/** @var \stdClass $config */
		$config = $this->getConfig();
		$builder = $this->getContainerBuilder();

		// Bez cronové extension postavíme vlastní chybový kanál z lokálního configu.
		if (!$builder->hasDefinition(self::CONNECTOR_REPORTER)) {
			if ($config->url === null) {
				throw new MissingServiceException(
					'LiquidMonitorLogger: register the LiquidMonitorConnector extension, or set url (+ apiKey) on liquidMonitorLogger for standalone error-only reporting.',
				);
			}

			$builder->addDefinition($this->prefix('errorReporter'))
				->setType(ErrorReporter::class)
				->addSetup('setConfiguration', [$config->url, $config->apiKey, $config->enabled, $config->verifyTls]);
		}

		if ($builder->hasDefinition('tracy.logger')) {
			$builder->removeDefinition('tracy.logger');
		}

		$builder->addDefinition('tracy.logger', new ServiceDefinition())
			->setType(LiquidMonitorLogger::class)
			->addSetup('setProperties', [
				$config->levels,
			]);
	}
}
