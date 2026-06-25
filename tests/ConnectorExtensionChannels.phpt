<?php

declare(strict_types=1);

use LiquidMonitorConnector\Bridges\LiquidMonitorConnectorDI;
use Nette\DI\Compiler;
use Nette\Schema\Processor;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$method = static fn ($setup): ?string => \is_array($setup->getEntity()) ? ($setup->getEntity()[1] ?? null) : $setup->getEntity();

/**
 * Zkompiluje extension nad daným raw configem a vrátí argumenty setupu
 * `setConfiguration` na definici `liquidMonitorConnector`.
 *
 * @param array<string, mixed> $rawConfig
 * @return array<int, mixed>
 */
$configure = static function (array $rawConfig) use ($method): array {
	$extension = new LiquidMonitorConnectorDI();
	$extension->setCompiler(new Compiler(), 'liquidMonitorConnector');
	$extension->setConfig((new Processor())->process($extension->getConfigSchema(), $rawConfig));
	$extension->loadConfiguration();

	$definition = $extension->getContainerBuilder()->getDefinition('liquidMonitorConnector');

	foreach ($definition->getSetup() as $setup) {
		if ($method($setup) === 'setConfiguration') {
			return $setup->arguments;
		}
	}

	Assert::fail('setConfiguration setup not found on liquidMonitorConnector definition');
};

// --- Legacy config: jen url + apiKey → oba kanály spadnou na sdílenou hodnotu. ---
Assert::same(
	['https://v1/api_connector', 'KEY1', true, 'https://v1/api_connector', 'KEY1'],
	$configure(['url' => 'https://v1/api_connector', 'apiKey' => 'KEY1']),
);

// --- Log override: crony na v1, chyby/logy na v2 (vlastní url i apiKey). ---
Assert::same(
	['https://v1/api_connector', 'KEY1', true, 'https://v2/api_connector', 'KEY2'],
	$configure([
		'url' => 'https://v1/api_connector',
		'apiKey' => 'KEY1',
		'log' => ['url' => 'https://v2/api_connector', 'apiKey' => 'KEY2'],
	]),
);

// --- Cron override: crony jinam, logy dědí ze sdílené hodnoty. ---
Assert::same(
	['https://cron-monitor/api_connector', 'CRON_KEY', true, 'https://shared/api_connector', 'SHARED_KEY'],
	$configure([
		'url' => 'https://shared/api_connector',
		'apiKey' => 'SHARED_KEY',
		'cron' => ['url' => 'https://cron-monitor/api_connector', 'apiKey' => 'CRON_KEY'],
	]),
);

// --- Částečný override: log přepíše jen url, apiKey podědí ze sdílené hodnoty. ---
Assert::same(
	['https://shared/api_connector', 'SHARED_KEY', true, 'https://v2/api_connector', 'SHARED_KEY'],
	$configure([
		'url' => 'https://shared/api_connector',
		'apiKey' => 'SHARED_KEY',
		'log' => ['url' => 'https://v2/api_connector'],
	]),
);

// --- enabled:false projde beze změny resoluce kanálů. ---
Assert::same(
	['https://v1/api_connector', 'KEY1', false, 'https://v1/api_connector', 'KEY1'],
	$configure(['url' => 'https://v1/api_connector', 'apiKey' => 'KEY1', 'enabled' => false]),
);

echo "\nOK " . __FILE__ . "\n";
