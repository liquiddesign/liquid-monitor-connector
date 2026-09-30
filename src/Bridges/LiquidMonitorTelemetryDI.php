<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Bridges;

use LiquidMonitorConnector\Telemetry\NetteApplicationBridge;
use LiquidMonitorConnector\Telemetry\StormBridge;
use LiquidMonitorConnector\Telemetry\TelemetryConfig;
use Nette\Application\Application;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * Výkonová telemetrie (requesty, fáze, SQL, studené procesy) — viz README, sekce Telemetrie.
 *
 *     extensions:
 *         liquidMonitorTelemetry: LiquidMonitorConnector\Bridges\LiquidMonitorTelemetryDI
 *
 *     liquidMonitorTelemetry:
 *         enabled: true # výchozí false
 *         environment: prod
 *
 * Vypnutá extension nic nenapojí — nulová režie.
 */
class LiquidMonitorTelemetryDI extends \Nette\DI\CompilerExtension
{
	private const STORM_CONNECTION = 'StORM\Connection';

	public function getConfigSchema(): Schema
	{
		$defaults = new TelemetryConfig();

		return Expect::structure([
			'enabled' => Expect::bool($defaults->enabled),
			'environment' => Expect::string()->nullable(),
			'host' => Expect::string($defaults->host),
			'port' => Expect::int($defaults->port),
			// NEON `1` / `20` je int — Expect::float() by ho odmítl a shodil celý DI kontejner
			'sampleRate' => Expect::type('int|float')->default($defaults->sampleRate)->castTo('float')->min(0.0)->max(1.0),
			'slowRequestMs' => Expect::type('int|float')->default($defaults->slowRequestMs)->castTo('float'),
			'slowSpanMs' => Expect::type('int|float')->default($defaults->slowSpanMs)->castTo('float'),
			'maxSpans' => Expect::int($defaults->maxSpans),
			'maxKeysPerType' => Expect::int($defaults->maxKeysPerType),
			'maxSlowSpans' => Expect::int($defaults->maxSlowSpans),
			'maxBacktraces' => Expect::int($defaults->maxBacktraces),
			'maxPayloadBytes' => Expect::int($defaults->maxPayloadBytes)->max(65000),
			'cli' => Expect::bool($defaults->cli),
			'storm' => Expect::bool($defaults->storm),
			// Kam agent posílá data; výchozí = url + apiKey z liquidMonitorConnector
			'url' => Expect::string()->nullable(),
			'apiKey' => Expect::string()->nullable(),
			'agent' => Expect::structure([
				'autostart' => Expect::bool($defaults->agentAutostart),
				// výchozí %tempDir%/telemetry
				'outDir' => Expect::string()->nullable(),
				'maxRuntime' => Expect::int($defaults->agentMaxRuntime),
			]),
		]);
	}

	public function loadConfiguration(): void
	{
		/** @var \stdClass $config */
		$config = $this->getConfig();
		/** @var \stdClass $agent */
		$agent = $config->agent;
		$tempDir = $this->getContainerBuilder()->parameters['tempDir'] ?? null;

		$this->getContainerBuilder()->addDefinition($this->prefix('config'))
			->setFactory(TelemetryConfig::class, [
				'enabled' => $config->enabled,
				'environment' => $config->environment,
				'host' => $config->host,
				'port' => $config->port,
				'sampleRate' => $config->sampleRate,
				'slowRequestMs' => $config->slowRequestMs,
				'slowSpanMs' => $config->slowSpanMs,
				'maxSpans' => $config->maxSpans,
				'maxKeysPerType' => $config->maxKeysPerType,
				'maxSlowSpans' => $config->maxSlowSpans,
				'maxBacktraces' => $config->maxBacktraces,
				'maxPayloadBytes' => $config->maxPayloadBytes,
				'cli' => $config->cli,
				'storm' => $config->storm,
				'agentAutostart' => $agent->autostart,
				'agentOutDir' => $agent->outDir ?? (\is_string($tempDir) ? $tempDir . '/telemetry' : null),
				'agentMaxRuntime' => $agent->maxRuntime,
				'monitorUrl' => $config->url,
				'apiKey' => $config->apiKey,
			]);
	}

	public function beforeCompile(): void
	{
		/** @var \stdClass $config */
		$config = $this->getConfig();

		if (!$config->enabled) {
			return;
		}

		$builder = $this->getContainerBuilder();
		$configReference = '@' . $this->prefix('config');

		foreach ($builder->findByType(Application::class) as $definition) {
			if ($definition instanceof ServiceDefinition) {
				$definition->addSetup([NetteApplicationBridge::class, 'attach'], ['@self', $configReference]);
			}
		}

		if (!$config->storm || !\class_exists(self::STORM_CONNECTION)) {
			return;
		}

		foreach ($builder->findByType(self::STORM_CONNECTION) as $definition) {
			if ($definition instanceof ServiceDefinition) {
				$definition->addSetup([StormBridge::class, 'attach'], ['@self', $configReference]);
			}
		}
	}
}
