<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Bridges;

use LiquidMonitorConnector\Actions\GetCronService;
use LiquidMonitorConnector\Cron;
use LiquidMonitorConnector\ErrorReporter;
use LiquidMonitorConnector\Scheduler\CronDefinitionCatalog;
use LiquidMonitorConnector\Scheduler\LocalQueue;
use LiquidMonitorConnector\Scheduler\SchedulerConfig;
use LiquidMonitorConnector\Scheduler\SchedulerHealth;
use LiquidMonitorConnector\Scheduler\SqliteConnection;
use LiquidMonitorConnector\Worker\CronJobHandlerRegistry;
use LiquidMonitorConnector\Worker\WorkerHandlerDiscovery;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\InvalidConfigurationException;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * Registrace v host aplikaci:
 *
 *   liquidMonitorConnector:
 *       url: https://monitor.example/api/connector # sdílený fallback (povinné)
 *       apiKey: SHARED_KEY
 *       enabled: true
 *
 * Crony a logy/chyby lze nasměrovat na různé instance monitoru. Volitelné
 * per-kanálové overrides (každý nese vlastní `url` i `apiKey`); cokoli, co se
 * vynechá, dědí z top-level `url`/`apiKey`:
 *
 *   liquidMonitorConnector:
 *       url: https://v1-monitor.example/api/connector # crony → starý monitor
 *       apiKey: KEY_V1
 *       log: # chyby/logy → nový monitor
 *           url: https://v2-monitor.example/api/connector
 *           apiKey: KEY_V2
 *
 * TLS ověření certifikátu monitoru je defaultně zapnuté (`verifyTls: true`). Vypnout
 * (nebo nasměrovat na vlastní CA bundle) jde jen kvůli lokálnímu dev se self-signed
 * certem — `verifyTls: false` otevírá MITM odposlech `apiKey`, takže na produkci
 * nikdy. Preferovaný způsob pro self-signed dev cert je cesta k CA bundlu:
 *
 *   liquidMonitorConnector:
 *       url: https://monitor.local/api/connector
 *       apiKey: KEY
 *       verifyTls: false # dev only! vypne ověření certifikátu
 *       # verifyTls: /etc/ssl/certs/dev-ca.pem # lepší: ověřovat proti vlastní CA
 *
 * Pull-model cron worker (`bin/monitor-worker`) resolves handlers from:
 *
 *       workerHandlers: auto
 *
 * or explicit map:
 *
 *       workerHandlers:
 *           import: @App\Cron\ImportHandler
 *
 * Local cron scheduler `lqcron` (connector 4, docs/v4-local-scheduler.md § 4):
 *
 *       scheduler:
 *           enabled: true
 *           environment: %environment%
 *           stateDir: /srv/app/shared/lqcron
 *           timezone: Europe/Prague
 *
 * `scheduler.environment` also drives `schedules` / `environments` of `#[PullCron]`
 * for `monitor-worker enqueue-due` when the scheduler itself is off.
 */
class LiquidMonitorConnectorDI extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'url' => Expect::string()->required(),
			'apiKey' => Expect::string(null),
			'enabled' => Expect::bool(true),
			// TLS ověření certifikátu monitoru: true = ověřovat (default), false = vypnuto
			// (jen dev se self-signed certem), string = cesta k vlastnímu CA bundlu.
			'verifyTls' => Expect::anyOf(Expect::bool(), Expect::string())->default(true),
			'cron' => $this->channelSchema(),
			'log' => $this->channelSchema(),
			'workerHandlers' => Expect::anyOf(
				Expect::string('auto'),
				Expect::arrayOf(Expect::string()),
			)->default([]),
			'scheduler' => $this->schedulerSchema(),
		]);
	}

	public function loadConfiguration(): void
	{
		/** @var \stdClass $config */
		$config = $this->getConfig();

		$cronUrl = $config->cron['url'] ?? $config->url;
		$cronApiKey = $config->cron['apiKey'] ?? $config->apiKey;
		$logUrl = $config->log['url'] ?? $config->url;
		$logApiKey = $config->log['apiKey'] ?? $config->apiKey;

		$builder = $this->getContainerBuilder();

		$cron = $builder->addDefinition('liquidMonitorConnector')->setType(Cron::class);
		$cron->addSetup('setConfiguration', [$cronUrl, $cronApiKey, $config->enabled, $logUrl, $logApiKey, $config->verifyTls]);

		$builder->addDefinition('liquidMonitorConnector.getCronService')->setType(GetCronService::class);

		$schedulerConfig = $this->createSchedulerConfig($config->scheduler);
		$this->assertSchedulerEnvironment($schedulerConfig);

		$builder->addDefinition('liquidMonitorConnector.scheduler.config')
			->setType(SchedulerConfig::class)
			->setFactory(SchedulerConfig::class, (array) $schedulerConfig);

		$builder->addDefinition('liquidMonitorConnector.scheduler.catalog')
			->setType(CronDefinitionCatalog::class)
			->setFactory(CronDefinitionCatalog::class, [[], $schedulerConfig->environment, []]);

		$localQueue = null;

		if ($schedulerConfig->isLocal()) {
			\assert($schedulerConfig->stateDir !== null);
			$localQueue = $builder->addDefinition('liquidMonitorConnector.scheduler.localQueue')
				->setType(LocalQueue::class)
				->setFactory(LocalQueue::class, [$schedulerConfig->stateDir, '@liquidMonitorConnector.scheduler.catalog']);
		}

		if ($schedulerConfig->isSchedulerRunning()) {
			\assert($schedulerConfig->stateDir !== null);
			$builder->addDefinition('liquidMonitorConnector.scheduler.health')
				->setType(SchedulerHealth::class)
				->setFactory(SchedulerHealth::class, [$schedulerConfig->stateDir]);
		}

		$cron->addSetup('setScheduler', [
			'@liquidMonitorConnector.scheduler.config',
			'@liquidMonitorConnector.scheduler.catalog',
			$localQueue !== null ? '@liquidMonitorConnector.scheduler.localQueue' : null,
		]);

		// Chybový/logový kanál jako samostatná služba — `LiquidMonitorLogger` na ni
		// míří místo na `Cron`, takže ho lze provozovat i bez cronů (viz LiquidMonitorLoggerDI).
		$builder->addDefinition('liquidMonitorConnector.errorReporter')
			->setType(ErrorReporter::class)
			->addSetup('setConfiguration', [$logUrl, $logApiKey, $config->enabled, $config->verifyTls]);
	}

	public function beforeCompile(): void
	{
		if (!\class_exists(CronJobHandlerRegistry::class)) {
			return;
		}

		/** @var \stdClass $config */
		$config = $this->getConfig();
		$builder = $this->getContainerBuilder();
		$handlers = WorkerHandlerDiscovery::resolve($builder, $config->workerHandlers);

		$catalog = $builder->getDefinition('liquidMonitorConnector.scheduler.catalog');
		\assert($catalog instanceof ServiceDefinition);
		$catalog->setFactory(CronDefinitionCatalog::class, [
			$this->handlerClasses($handlers),
			$config->scheduler->environment,
			$this->configFiles(),
		]);

		if ($handlers === []) {
			return;
		}

		$builder->addDefinition('liquidMonitorConnector.workerHandlerRegistry')
			->setType(CronJobHandlerRegistry::class)
			->setFactory(CronJobHandlerRegistry::class, ['handlers' => $handlers]);

		$builder->addAlias(CronJobHandlerRegistry::class, 'liquidMonitorConnector.workerHandlerRegistry');
	}

	/**
	 * Cron code => handler class, resolved from service definitions without
	 * instantiating the handlers (enqueue-due / plan / export read only attributes).
	 * @param array<string, string> $handlers cron code => `@service` or `@Class`
	 * @return array<string, class-string>
	 */
	private function handlerClasses(array $handlers): array
	{
		$builder = $this->getContainerBuilder();
		$classes = [];

		foreach ($handlers as $code => $reference) {
			$name = \ltrim($reference, '@');
			$type = null;

			if ($builder->hasDefinition($name)) {
				$type = $builder->getDefinition($name)->getType();
			} elseif (\class_exists($name)) {
				$type = $name;
			} elseif (\interface_exists($name)) {
				$found = $builder->getByType($name);
				$type = $found !== null ? $builder->getDefinition($found)->getType() : null;
			}

			if ($type === null || !\class_exists($type)) {
				continue;
			}

			$classes[$code] = $type;
		}

		\ksort($classes);

		return $classes;
	}

	/**
	 * NEON files the container is compiled from — `lqcron` re-exports definitions
	 * when one of them changes (§ 5.2).
	 * @return list<string>
	 */
	private function configFiles(): array
	{
		$files = [];
		$dependencies = $this->compiler->exportDependencies();

		foreach (\array_keys($dependencies[1]) as $file) {
			$file = (string) $file;

			if (!\str_ends_with($file, '.neon')) {
				continue;
			}

			$files[] = $file;
		}

		\sort($files);

		return $files;
	}

	private function schedulerSchema(): Schema
	{
		return Expect::structure([
			'enabled' => Expect::bool(false),
			'shadow' => Expect::bool(false),
			'environment' => Expect::string()->nullable(),
			'stateDir' => Expect::string()->nullable(),
			'timezone' => Expect::string()->nullable(),
			'maxParallel' => Expect::int(3)->min(1),
			'starvationSeconds' => Expect::int(300)->min(0),
			'runLogMaxBytes' => Expect::int(52428800)->min(131072),
			'killGraceSeconds' => Expect::int(30)->min(1),
			'reportLogTail' => Expect::bool(true),
			'logRedactor' => Expect::string()->nullable(),
			'remoteCommands' => Expect::listOf(Expect::anyOf('cron.set_active', 'cron.run_now', 'run.cancel', 'scheduler.pause', 'scheduler.resume'))
				->default(SchedulerConfig::DEFAULT_REMOTE_COMMANDS),
			'resources' => Expect::arrayOf(Expect::int()->min(1)),
			'phpBinary' => Expect::string()->nullable(),
			'bootstrap' => Expect::string()->nullable(),
			'services' => Expect::arrayOf(Expect::structure([
				'command' => Expect::anyOf(Expect::string(), Expect::listOf(Expect::string()))->required(),
				'mode' => Expect::anyOf(...SchedulerConfig::SERVICE_MODES)->default('watchdog'),
			])->castTo('array')),
		]);
	}

	private function createSchedulerConfig(\stdClass $scheduler): SchedulerConfig
	{
		$services = [];

		/** @var array{command: string|list<string>, mode: string} $service */
		foreach ($scheduler->services as $name => $service) {
			$command = $service['command'];
			$services[(string) $name] = [
				'command' => \is_string($command) ? ['/bin/sh', '-c', $command] : $command,
				'mode' => $service['mode'],
			];
		}

		$resources = [];

		foreach ($scheduler->resources as $name => $capacity) {
			$resources[(string) $name] = (int) $capacity;
		}

		return new SchedulerConfig(
			enabled: $scheduler->enabled,
			shadow: $scheduler->shadow,
			environment: $scheduler->environment,
			stateDir: $scheduler->stateDir !== null ? \rtrim($scheduler->stateDir, '/') : null,
			timezone: $scheduler->timezone ?? \date_default_timezone_get(),
			maxParallel: $scheduler->maxParallel,
			starvationSeconds: $scheduler->starvationSeconds,
			runLogMaxBytes: $scheduler->runLogMaxBytes,
			killGraceSeconds: $scheduler->killGraceSeconds,
			reportLogTail: $scheduler->reportLogTail,
			logRedactor: $scheduler->logRedactor,
			remoteCommands: \array_values(\array_unique($scheduler->remoteCommands)),
			resources: $resources,
			phpBinary: $scheduler->phpBinary,
			bootstrap: $scheduler->bootstrap,
			services: $services,
		);
	}

	/**
	 * Operating conditions of `stateDir` checked when the container compiles (§ 4.1).
	 */
	private function assertSchedulerEnvironment(SchedulerConfig $config): void
	{
		try {
			new \DateTimeZone($config->timezone);
		} catch (\Throwable) {
			throw new InvalidConfigurationException(\sprintf('liquidMonitorConnector.scheduler.timezone: unknown timezone "%s".', $config->timezone));
		}

		if (!$config->isSchedulerRunning()) {
			return;
		}

		if ($config->stateDir === null || $config->stateDir === '' || !\str_starts_with($config->stateDir, '/')) {
			throw new InvalidConfigurationException('liquidMonitorConnector.scheduler.stateDir is required as an absolute path outside the release slot when the scheduler is enabled.');
		}

		if (SqliteConnection::availableDriver() === null) {
			throw new InvalidConfigurationException('liquidMonitorConnector.scheduler needs the pdo_sqlite or sqlite3 PHP extension (in FPM too — jobs are enqueued from web requests).');
		}

		$version = SqliteConnection::libraryVersion();

		if ($version === null || \version_compare($version, SqliteConnection::MIN_SQLITE_VERSION, '<')) {
			throw new InvalidConfigurationException(\sprintf(
				'liquidMonitorConnector.scheduler needs SQLite >= %s in PHP, found %s.',
				SqliteConnection::MIN_SQLITE_VERSION,
				$version ?? 'none',
			));
		}

		foreach (\array_keys($config->resources) as $name) {
			if ($name === '') {
				throw new InvalidConfigurationException('liquidMonitorConnector.scheduler.resources: empty resource group name.');
			}
		}
	}

	/**
	 * Schéma jednoho odchozího kanálu (crony / logy). Obě hodnoty jsou volitelné —
	 * null znamená fallback na top-level `url`/`apiKey`.
	 */
	private function channelSchema(): Schema
	{
		return Expect::structure([
			'url' => Expect::string()->nullable(),
			'apiKey' => Expect::string()->nullable(),
		])->castTo('array');
	}
}
