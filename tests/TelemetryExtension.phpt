<?php

declare(strict_types=1);

namespace StORM {
	// Náhrada StORM\Connection se setQueryObserver() — connector na StORM nezávisí.
	class Connection
	{
		public ?\Closure $observer = null;

		public function setQueryObserver(?\Closure $observer): void
		{
			$this->observer = $observer;
		}
	}
}

namespace {
	use LiquidMonitorConnector\Bridges\LiquidMonitorTelemetryDI;
	use LiquidMonitorConnector\Telemetry\Recorder;
	use LiquidMonitorConnector\Telemetry\TelemetryConfig;
	use Nette\Application\Application;
	use Nette\DI\Compiler;
	use Nette\DI\Container;
	use Nette\DI\ContainerLoader;
	use Tester\Assert;

	require __DIR__ . '/../vendor/autoload.php';

	Tester\Environment::setup();

	final class FakeApplication extends Application
	{
		public function __construct()
		{
		}
	}

	$tempDir = __DIR__ . '/temp/telemetry-di-' . \getmypid();

	$build = static function (array $telemetry) use ($tempDir): Container {
		$loader = new ContainerLoader($tempDir, true);
		$class = $loader->load(static function (Compiler $compiler) use ($telemetry, $tempDir): void {
			$compiler->addExtension('liquidMonitorTelemetry', new LiquidMonitorTelemetryDI());
			$compiler->addConfig([
				'parameters' => ['tempDir' => $tempDir],
				'liquidMonitorTelemetry' => $telemetry,
				'services' => [
					'application' => FakeApplication::class,
					'connection' => StORM\Connection::class,
				],
			]);
		}, \md5((string) \json_encode($telemetry)));

		$container = new $class();
		\assert($container instanceof Container);

		return $container;
	};

	// --- Výchozí stav: vypnuto, nic se nenapojí. ---
	Recorder::reset();
	$container = $build([]);
	$config = $container->getByType(TelemetryConfig::class);
	Assert::false($config->enabled);
	Assert::same($tempDir . '/telemetry', $config->agentOutDir);
	$application = $container->getByType(Application::class);
	Assert::same([], $application->onShutdown);
	Assert::null($container->getByType(StORM\Connection::class)->observer);
	Assert::false(Recorder::isActive());

	// --- Zapnuto (cli kvůli testu): Application i StORM dostanou háčky, recorder běží. ---
	$container = $build(['enabled' => true, 'cli' => true, 'environment' => 'test', 'sampleRate' => 0.5]);
	Assert::same(0.5, $container->getByType(TelemetryConfig::class)->sampleRate);
	$application = $container->getByType(Application::class);
	Assert::count(1, $application->onRequest);
	Assert::count(1, $application->onResponse);
	Assert::count(1, $application->onError);
	Assert::count(1, $application->onShutdown);
	Assert::true(Recorder::isActive());
	Assert::type(\Closure::class, $container->getByType(StORM\Connection::class)->observer);

	// --- storm: false → SQL se nenapojí. ---
	Recorder::reset();
	$container = $build(['enabled' => true, 'cli' => true, 'storm' => false]);
	Assert::null($container->getByType(StORM\Connection::class)->observer);

	Recorder::reset();
	Nette\Utils\FileSystem::delete($tempDir);
}
