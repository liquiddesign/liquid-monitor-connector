<?php

declare(strict_types=1);

use LiquidMonitorConnector\Bridges\LiquidMonitorConnectorDI;
use LiquidMonitorConnector\Bridges\LiquidMonitorLoggerDI;
use LiquidMonitorConnector\ErrorReporter;
use LiquidMonitorConnector\LiquidMonitorLogger;
use Nette\DI\Compiler;
use Nette\DI\MissingServiceException;
use Nette\Schema\Processor;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// addSetup() wraps the call as [Reference(self), 'method'] — pull out the method name.
$method = static fn ($setup): ?string => \is_array($setup->getEntity()) ? ($setup->getEntity()[1] ?? null) : $setup->getEntity();

/**
 * @return array<int, mixed>
 */
$reporterArgs = static function ($definition) use ($method): array {
	foreach ($definition->getSetup() as $setup) {
		if ($method($setup) === 'setConfiguration') {
			return $setup->arguments;
		}
	}

	Assert::fail('setConfiguration setup not found on ErrorReporter definition');
};

// Both extensions are driven over one shared Compiler so they see each other's definitions.
$loadConnector = static function (Compiler $compiler, array $rawConfig): LiquidMonitorConnectorDI {
	$extension = new LiquidMonitorConnectorDI();
	$extension->setCompiler($compiler, 'liquidMonitorConnector');
	$extension->setConfig((new Processor())->process($extension->getConfigSchema(), $rawConfig));
	$extension->loadConfiguration();

	return $extension;
};

$loadLogger = static function (Compiler $compiler, array $rawConfig): LiquidMonitorLoggerDI {
	$extension = new LiquidMonitorLoggerDI();
	$extension->setCompiler($compiler, 'liquidMonitorLogger');
	$extension->setConfig((new Processor())->process($extension->getConfigSchema(), $rawConfig));
	$extension->loadConfiguration();

	return $extension;
};

// --- Standalone (error-only): jen logger s vlastní url/apiKey, žádná Cron služba. ---
$compiler = new Compiler();
$logger = $loadLogger($compiler, ['url' => 'https://mon/api_connector', 'apiKey' => 'KEY']);
$builder = $logger->getContainerBuilder();

Assert::false($builder->hasDefinition('liquidMonitorConnector')); // cronová služba se nevyžaduje

$reporter = $builder->getDefinitionByType(ErrorReporter::class); // právě jedna
Assert::same(['https://mon/api_connector', 'KEY', true], $reporterArgs($reporter));
Assert::same(LiquidMonitorLogger::class, $builder->getDefinition('tracy.logger')->getType());

// --- Standalone bez url → fail-fast, jasná hláška. ---
Assert::exception(
	static fn () => $loadLogger(new Compiler(), []),
	MissingServiceException::class,
);

// --- Kombinovaný režim: logger reusuje chybový kanál cronové extension (per-channel log: routing). ---
$compiler = new Compiler();
$loadConnector($compiler, [
	'url' => 'https://shared/api_connector',
	'apiKey' => 'SHARED',
	'log' => ['url' => 'https://errors/api_connector', 'apiKey' => 'ERR'],
]);
$logger = $loadLogger($compiler, []);
$builder = $logger->getContainerBuilder();

Assert::false($builder->hasDefinition('liquidMonitorLogger.errorReporter')); // žádný duplicitní reporter
Assert::same(
	['https://errors/api_connector', 'ERR', true],
	$reporterArgs($builder->getDefinition('liquidMonitorConnector.errorReporter')),
);
Assert::same(LiquidMonitorLogger::class, $builder->getDefinition('tracy.logger')->getType());

echo "\nOK " . __FILE__ . "\n";
