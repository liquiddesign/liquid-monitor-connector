<?php

declare(strict_types=1);

use LiquidMonitorConnector\Bridges\LiquidMonitorConnectorDI;
use LiquidMonitorConnector\Cron;
use LiquidMonitorConnector\HealthCheck\HealthCheckStatusEnum;
use LiquidMonitorConnector\Scheduler\CronDefinitionCatalog;
use LiquidMonitorConnector\Scheduler\LocalQueue;
use LiquidMonitorConnector\Scheduler\SchedulerConfig;
use LiquidMonitorConnector\Scheduler\SchedulerHealth;
use LiquidMonitorConnector\Worker\CronJobHandler;
use LiquidMonitorConnector\Worker\ExportDefinitionsCommand;
use LiquidMonitorConnector\Worker\PullCron;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\DI\InvalidConfigurationException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/fixtures/scheduler.php';

Tester\Environment::setup();

#[PullCron(schedule: '*/30 * * * *', schedules: ['production' => '30 * * * *'], timeout: 0, resources: ['qi'], missed: 'once')]
final class SyncPricesHandler implements CronJobHandler
{
	public function execute(?array $arguments): void
	{
	}
}

#[PullCron(schedule: '0 3 * * *', after: SyncPricesHandler::class, environments: ['production'])]
final class RebuildFeedHandler implements CronJobHandler
{
	public function execute(?array $arguments): void
	{
	}
}

#[PullCron(schedule: '0 1 * * *', after: CycleBHandler::class)]
final class CycleAHandler implements CronJobHandler
{
	public function execute(?array $arguments): void
	{
	}
}

#[PullCron(schedule: '0 2 * * *', after: CycleAHandler::class)]
final class CycleBHandler implements CronJobHandler
{
	public function execute(?array $arguments): void
	{
	}
}

final class NoAttributeJobHandler implements CronJobHandler
{
	public function execute(?array $arguments): void
	{
	}
}

$tempDir = sys_get_temp_dir() . '/scheduler-extension-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($tempDir, 0777, true);

$compile = static function (string $neon) use ($tempDir): Container {
	$file = $tempDir . '/config-' . md5($neon) . '.neon';
	file_put_contents($file, $neon);
	$loader = new ContainerLoader($tempDir . '/cache', true);
	$class = $loader->load(static function (Compiler $compiler) use ($file): void {
		$compiler->addExtension('liquidMonitorConnector', new LiquidMonitorConnectorDI());
		$compiler->loadConfig($file);
	}, md5($neon));

	/** @var Container $container */
	$container = new $class();

	return $container;
};

$stateDir = schedulerStateDir('extension');
$base = <<<NEON
services:
	- Nette\\Http\\Request(Nette\\Http\\UrlScript('http://localhost/'))
	- SyncPricesHandler
	- RebuildFeedHandler
	- NoAttributeJobHandler

liquidMonitorConnector:
	url: 'https://lqdeck.example/api/connector'
	apiKey: 'KEY'
	workerHandlers: auto

NEON;

// --- Scheduler off: config + catalog exist (enqueue-due / plan), no local queue. ---
$container = $compile($base . "\tscheduler:\n\t\tenvironment: production\n\t\ttimezone: Europe/Prague\n");
$config = $container->getByType(SchedulerConfig::class);
Assert::false($config->isLocal());
Assert::same('production', $config->environment);
Assert::null($container->getByType(LocalQueue::class, false));
Assert::null($container->getByType(SchedulerHealth::class, false));

$catalog = $container->getByType(CronDefinitionCatalog::class);
Assert::same(['rebuildFeed', 'syncPrices'], array_keys($catalog->all()));
Assert::same('30 * * * *', $catalog->get('syncPrices')?->schedule());
Assert::true(count(array_filter($catalog->configFiles(), static fn (string $f): bool => str_ends_with($f, '.neon'))) >= 1);
Assert::null($container->getByType(Cron::class)->getLocalQueue());

// --- Scheduler on: local queue wired into Cron, health check available. ---
$localNeon = $base . <<<NEON
	scheduler:
		enabled: true
		environment: production
		stateDir: {$stateDir}
		timezone: Europe/Prague
		maxParallel: 2
		resources:
			qi: 1
		logRedactor: App\\Redactor
		services:
			productsDaemon:
				command: [php, daemon.php]
			agent:
				command: 'php agent.php --watch'
				mode: keepalive

NEON;
$container = $compile($localNeon);
$cron = $container->getByType(Cron::class);
Assert::type(LocalQueue::class, $cron->getLocalQueue());
Assert::type(SchedulerHealth::class, $container->getByType(SchedulerHealth::class));

// --- export-definitions: the contract shape (docs/v4-contract.md § 4). ---
$command = new ExportDefinitionsCommand(static fn (?string $bootstrap): Container => $container);
$output = new BufferedOutput();
$exportFile = $tempDir . '/slot/temp/lqcron/export.json';
Assert::same(0, $command->run(new ArrayInput(['--bootstrap' => __FILE__, '--output' => $exportFile]), $output));
Assert::same('0640', substr(sprintf('%o', fileperms($exportFile)), -4));
$export = json_decode((string) file_get_contents($exportFile), true);

Assert::same(1, $export['format']);
Assert::same(64, strlen($export['hash']));
Assert::same($stateDir, $export['config']['state_dir']);
Assert::same('Europe/Prague', $export['config']['timezone']);
Assert::same(2, $export['config']['max_parallel']);
Assert::same(['qi' => 1], $export['config']['resources']);
Assert::true($export['config']['log_redactor']);
Assert::same(['php', 'daemon.php'], $export['config']['services']['productsDaemon']['command']);
Assert::same('watchdog', $export['config']['services']['productsDaemon']['mode']);
Assert::same(['/bin/sh', '-c', 'php agent.php --watch'], $export['config']['services']['agent']['command']);
Assert::same('https://lqdeck.example/api/connector', $export['config']['url']);
Assert::same('KEY', $export['config']['api_key']);
Assert::same(realpath(__FILE__), $export['config']['bootstrap']);
Assert::same(['cron.set_active', 'cron.run_now', 'run.cancel'], $export['config']['remote_commands']);

$crons = array_column($export['crons'], null, 'code');
Assert::same(['rebuildFeed', 'syncPrices'], array_keys($crons));
Assert::same('30 * * * *', $crons['syncPrices']['schedule']);
Assert::same('schedules.production', $crons['syncPrices']['schedule_source']);
Assert::same(0, $crons['syncPrices']['timeout']);
Assert::same(['qi'], $crons['syncPrices']['resources']);
Assert::same('once', $crons['syncPrices']['missed']);
Assert::same('exclusive', $crons['syncPrices']['concurrency_mode']);
Assert::same('syncPrices', $crons['rebuildFeed']['after']);
Assert::same(['syncPrices: timeout 0 = no limit (LQDeck would read it as 1000 s)'], $export['warnings']);

// Same input, same hash: lqcron only re-applies a changed export.
$command->run(new ArrayInput(['--bootstrap' => __FILE__, '--output' => $exportFile]), new BufferedOutput());
Assert::same($export['hash'], json_decode((string) file_get_contents($exportFile), true)['hash']);

// --- shadow: true alone turns lqcron on (in shadow mode). ---
$shadowNeon = str_replace("\t\tenabled: true\n", "\t\tshadow: true\n", $localNeon);
$shadowContainer = $compile($shadowNeon);
Assert::null($shadowContainer->getByType(Cron::class)->getLocalQueue()); // v3 keeps running the jobs
$shadowExport = ExportDefinitionsCommand::build(
	$shadowContainer->getByType(SchedulerConfig::class),
	$shadowContainer->getByType(CronDefinitionCatalog::class),
	$shadowContainer->getByType(Cron::class),
	__FILE__,
	$tempDir,
);
Assert::true($shadowExport['config']['enabled']);
Assert::true($shadowExport['config']['shadow']);

// --- an `after` cycle fails the export instead of running two crons after each other forever ---
$cyclic = new CronDefinitionCatalog(['cycleA' => CycleAHandler::class, 'cycleB' => CycleBHandler::class]);
Assert::exception(
	static fn () => ExportDefinitionsCommand::build(new SchedulerConfig(), $cyclic, $cron, __FILE__, $tempDir),
	InvalidArgumentException::class,
	'%a%form a cycle%a%',
);

// --- Compile-time checks of § 4.1. ---
Assert::exception(
	static fn () => $compile($base . "\tscheduler:\n\t\tenabled: true\n"),
	InvalidConfigurationException::class,
	'%a%stateDir is required%a%',
);
Assert::exception(
	static fn () => $compile($base . "\tscheduler:\n\t\tenabled: true\n\t\tstateDir: relative/dir\n"),
	InvalidConfigurationException::class,
	'%a%absolute path%a%',
);
Assert::exception(
	static fn () => $compile($base . "\tscheduler:\n\t\ttimezone: Mars/Olympus\n"),
	InvalidConfigurationException::class,
	'%a%unknown timezone%a%',
);

// --- SchedulerHealth over the state the tick leaves in meta. ---
$health = new SchedulerHealth($stateDir);
$byName = static function (array $checks): array {
	$map = [];

	foreach ($checks as $check) {
		$map[$check->getName()] = $check->getStatus();
	}

	return $map;
};

$now = 1_760_000_000_000;
$statuses = $byName($health->checks($now));
Assert::same(HealthCheckStatusEnum::CRITICAL, $statuses['lqcron.tick']); // never ticked
Assert::same(HealthCheckStatusEnum::WARNING, $statuses['lqcron.deliver']);

schedulerExec($stateDir, "INSERT INTO meta (key, value) VALUES
	('last_tick_seen_at', '" . ($now - 30_000) . "'),
	('deliver_last_ok_at', '" . ($now - 60_000) . "'),
	('pause_deploy', '{\"since\": " . ($now - 1_000_000) . "}'),
	('export_error', 'boom'),
	('tick_refused', 'schema')");
schedulerExec($stateDir, "INSERT INTO outbox_rejected (seq, event_id, type, payload, occurred_at, created_at, rejected_at, reason) VALUES (1, 'e1', 'run.started', '{}', 1, 1, 1, 'invalid')");

$statuses = $byName($health->checks($now));
Assert::same(HealthCheckStatusEnum::OK, $statuses['lqcron.tick']);
Assert::same(HealthCheckStatusEnum::OK, $statuses['lqcron.deliver']);
Assert::same(HealthCheckStatusEnum::WARNING, $statuses['lqcron.pause']);
Assert::same(HealthCheckStatusEnum::WARNING, $statuses['lqcron.definitions']);
Assert::same(HealthCheckStatusEnum::CRITICAL, $statuses['lqcron.refused']);
Assert::same(HealthCheckStatusEnum::WARNING, $statuses['lqcron.rejected']);
Assert::same(HealthCheckStatusEnum::OK, $statuses['lqcron.stateDir']);

$missingHealth = new SchedulerHealth($tempDir . '/nowhere');
Assert::same(HealthCheckStatusEnum::CRITICAL, $missingHealth->checks()[0]->getStatus());

echo "\nOK " . __FILE__ . "\n";
