<?php

declare(strict_types=1);

use LiquidMonitorConnector\Cron;
use LiquidMonitorConnector\Scheduler\CronDefinitionCatalog;
use LiquidMonitorConnector\Scheduler\SchedulerConfig;
use LiquidMonitorConnector\Worker\EnqueueDueCommand;
use LiquidMonitorConnector\Worker\PlanCommand;
use LiquidMonitorConnector\Worker\PullCron;
use Nette\DI\Container;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tester\Assert;
use Tracy\Debugger;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();
Debugger::$logDirectory = \sys_get_temp_dir();

#[PullCron(schedule: '*/30 * * * *', schedules: ['production' => '30 * * * *', 'test' => '15 */4 * * *'])]
final class MergadoPricesHandler
{
}

#[PullCron(schedule: '* * * * *', environments: ['production'])]
final class EveryMinuteHandler
{
}

#[PullCron(schedule: '30 * * * *')]
final class BrokenRemoteHandler
{
}

final class RecordingEnqueueCron extends Cron
{
	/** @var list<string> */
	public array $scheduled = [];

	public function schedulePullJob(string $handlerClass, array|null $arguments = null, int|null $delaySeconds = null): void
	{
		if ($handlerClass === BrokenRemoteHandler::class) {
			throw new RuntimeException('429 queue full');
		}

		$this->scheduled[] = $handlerClass;
	}
}

$makeContainer = static function (SchedulerConfig $config, Cron $cron): Closure {
	$catalog = new CronDefinitionCatalog([
		'mergadoPrices' => MergadoPricesHandler::class,
		'everyMinute' => EveryMinuteHandler::class,
		'brokenRemote' => BrokenRemoteHandler::class,
	], $config->environment);

	return static fn (?string $bootstrap): Container => new class ($config, $catalog, $cron) extends Container {
		public function __construct(private SchedulerConfig $config, private CronDefinitionCatalog $catalog, private Cron $cron)
		{
			parent::__construct();
		}

		public function getByType(string $type, bool $throw = true): ?object
		{
			return match ($type) {
				SchedulerConfig::class => $this->config,
				CronDefinitionCatalog::class => $this->catalog,
				Cron::class => $this->cron,
				default => null,
			};
		}
	};
};

$tempDir = sys_get_temp_dir() . '/enqueue-due-' . getmypid();
@mkdir($tempDir, 0777, true);
$lockFile = $tempDir . '/enqueue-due.lock';
$prague = new DateTimeZone('Europe/Prague');

$run = static function (int $startedAt, SchedulerConfig $config, array $options = []) use ($makeContainer, $lockFile): array {
	$cron = new RecordingEnqueueCron(new Request(new UrlScript('http://localhost/')));
	$command = new EnqueueDueCommand($startedAt, $makeContainer($config, $cron));
	$output = new BufferedOutput();
	$exit = $command->run(new ArrayInput(['--lock-file' => $lockFile, '--lock-wait' => '1'] + $options), $output);

	return [$exit, $cron->scheduled, $output->fetch()];
};

$production = new SchedulerConfig(environment: 'production', timezone: 'Europe/Prague');

// --- The minute captured at process start is evaluated, not the time after boot. ---
// 10:30:59 Prague: production schedule `30 * * * *` is due, every-minute too;
// the failing cron does not stop the others, the exit code reports it.
$startedAt = (new DateTimeImmutable('2026-10-16 10:30:59', $prague))->getTimestamp();
[$exit, $scheduled, $output] = $run($startedAt, $production);
Assert::same(1, $exit);
Assert::same([EveryMinuteHandler::class, MergadoPricesHandler::class], $scheduled);
Assert::contains('brokenRemote failed for minute 2026-10-16 10:30: 429 queue full', $output);

// 10:00 production: only the every-minute cron.
[$exit, $scheduled] = $run((new DateTimeImmutable('2026-10-16 10:00:05', $prague))->getTimestamp(), $production);
Assert::same(0, $exit);
Assert::same([EveryMinuteHandler::class], $scheduled);

// --- schedules / environments per environment: test runs Mergado at 15 */4, never every-minute. ---
$test = new SchedulerConfig(environment: 'test', timezone: 'Europe/Prague');
[, $scheduled] = $run((new DateTimeImmutable('2026-10-16 12:15:00', $prague))->getTimestamp(), $test);
Assert::same([MergadoPricesHandler::class], $scheduled);
[, $scheduled] = $run((new DateTimeImmutable('2026-10-16 10:30:00', $prague))->getTimestamp(), $test);
Assert::same([], array_values(array_diff($scheduled, [BrokenRemoteHandler::class])));

// --- --minute overrides the captured minute (manual catch-up), --dry-run only prints. ---
[$exit, $scheduled, $output] = $run(0, $production, ['--minute' => '2026-10-16T11:30', '--dry-run' => true]);
Assert::same([], $scheduled);
Assert::contains('would enqueue mergadoPrices (30 * * * *)', $output);

// --- With the local scheduler on, lqcron plans and enqueue-due does nothing. ---
[$exit, $scheduled, $output] = $run($startedAt, new SchedulerConfig(enabled: true, environment: 'production', stateDir: '/tmp/x'));
Assert::same(0, $exit);
Assert::same([], $scheduled);
Assert::contains('lqcron plans the crons itself', $output);

// Shadow mode keeps v3 running, so enqueue-due still enqueues.
[, $scheduled] = $run($startedAt, new SchedulerConfig(enabled: true, shadow: true, environment: 'production', timezone: 'Europe/Prague', stateDir: '/tmp/x'));
Assert::same([EveryMinuteHandler::class, MergadoPricesHandler::class], $scheduled);

// --- A previous minute still holding the lock: wait, then skip with an error. ---
$handle = fopen($lockFile, 'c');
flock($handle, LOCK_EX);
[$exit, $scheduled, $output] = $run($startedAt, $production);
Assert::same(1, $exit);
Assert::same([], $scheduled);
Assert::contains('not acquired within 1 s', $output);
flock($handle, LOCK_UN);

// --- plan prints the effective schedule per environment. ---
$planOutput = static function (array $options) use ($makeContainer, $production): string {
	$command = new PlanCommand($makeContainer($production, new RecordingEnqueueCron(new Request(new UrlScript('http://localhost/')))));
	$output = new BufferedOutput();
	Assert::same(0, $command->run(new ArrayInput($options), $output));

	return $output->fetch();
};

$json = json_decode($planOutput(['--environment' => 'test', '--hours' => '24', '--from' => '2026-10-16T00:00', '--json' => true]), true);
Assert::same('test', $json['environment']);
$byCode = array_column($json['crons'], null, 'code');
Assert::same('15 */4 * * *', $byCode['mergadoPrices']['schedule']);
Assert::same('schedules.test', $byCode['mergadoPrices']['schedule_source']);
Assert::same(6, $byCode['mergadoPrices']['runs']);
Assert::same('2026-10-16T00:15:00+02:00', $byCode['mergadoPrices']['occurrences'][0]);
Assert::null($byCode['everyMinute']['schedule']);
Assert::same(0, $byCode['everyMinute']['runs']);

$text = $planOutput(['--hours' => '2', '--from' => '2026-10-16T10:00']);
Assert::contains('mergadoPrices', $text);
Assert::match('%A%everyMinute%a%* * * * *%a%120×%A%', $text);

echo "\nOK " . __FILE__ . "\n";
