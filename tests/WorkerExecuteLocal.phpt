<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/fixtures/scheduler.php';

Tester\Environment::setup();

// `monitor-worker execute --local` as lqcron run-job starts it (docs/v4-contract.md § 7.1).
$stateDir = schedulerStateDir('execute');
$bootstrap = $stateDir . '/bootstrap.php';
$autoload = realpath(__DIR__ . '/../vendor/autoload.php');

file_put_contents($bootstrap, <<<PHP
<?php

declare(strict_types=1);

require '{$autoload}';

use LiquidMonitorConnector\Cron;
use LiquidMonitorConnector\Worker\CronJobHandlerRegistry;
use LiquidMonitorConnector\Worker\InterruptibleCronJobHandler;
use Nette\DI\Container;
use Nette\Http\Request;
use Nette\Http\UrlScript;

\$cron = new Cron(new Request(new UrlScript('http://localhost/')));
\$cron->setConfiguration('http://127.0.0.1:9/api/connector', 'KEY', true);

return new class (\$cron) extends Container {
	public function __construct(private Cron \$cron)
	{
		parent::__construct();
	}

	public function getByType(string \$type, bool \$throw = true): ?object
	{
		\$cron = \$this->cron;

		return new CronJobHandlerRegistry([
			'ok' => new class (\$cron) implements InterruptibleCronJobHandler {
				public function __construct(private Cron \$cron) {}
				public function execute(?array \$arguments): void
				{
					\$this->cron->progressJob(['step' => 1]);
					\$this->cron->progressJob(['step' => 2]);
					echo "handler output\\n";
					\$this->cron->finishJob(['imported' => \$arguments['count'] ?? null]);
				}
				public function interrupt(): void {}
			},
			'binary' => new class (\$cron) implements InterruptibleCronJobHandler {
				public function __construct(private Cron \$cron) {}
				public function execute(?array \$arguments): void
				{
					\$this->cron->finishJob(['bytes' => "\xB1\x31 binary", 'ratio' => NAN]);
				}
				public function interrupt(): void {}
			},
			'binaryBoom' => new class implements InterruptibleCronJobHandler {
				public function execute(?array \$arguments): void
				{
					throw new \RuntimeException("SQLSTATE: bad bytes \xB1\x31 " . str_repeat('x', 200000));
				}
				public function interrupt(): void {}
			},
			'boom' => new class implements InterruptibleCronJobHandler {
				public function execute(?array \$arguments): void
				{
					throw new \RuntimeException('handler boom');
				}
				public function interrupt(): void {}
			},
			'explicitFail' => new class (\$cron) implements InterruptibleCronJobHandler {
				public function __construct(private Cron \$cron) {}
				public function execute(?array \$arguments): void
				{
					\$this->cron->failJob(['reason' => 'remote said no']);
				}
				public function interrupt(): void {}
			},
			'sleepy' => new class implements InterruptibleCronJobHandler {
				public function execute(?array \$arguments): void
				{
					sleep(30);
				}
				public function interrupt(): void
				{
					file_put_contents(getenv('LQCRON_STATE_DIR') . '/interrupted', '1');
				}
			},
		]);
	}
};
PHP);

$insertRun = static function (string $uuid, string $code, string $state = 'starting') use ($stateDir): void {
	schedulerExec($stateDir, "INSERT INTO runs (uuid, job_uuid, origin_uuid, cron_code, attempt, max_attempts, source, job_created_at, concurrency_mode, state, claimed_at, timeout)
		VALUES ('{$uuid}', 'job-{$uuid}', 'job-{$uuid}', '{$code}', 1, 2, 'schedule', 1, 'exclusive', '{$state}', 1, 0)");
};

$startExecute = static function (string $uuid, string $code, array|null $arguments = null) use ($stateDir, $bootstrap): Process {
	$argumentsFile = $stateDir . '/runs/' . $uuid . '.args.json';
	file_put_contents($argumentsFile, json_encode($arguments));
	$process = new Process(
		[PHP_BINARY, __DIR__ . '/../bin/monitor-worker', 'execute', '--local', '--run-id=' . $uuid, '--cron-code=' . $code, '--arguments-file=' . $argumentsFile, '--bootstrap=' . $bootstrap],
		$stateDir,
		['LQCRON_RUN_ID' => $uuid, 'LQCRON_STATE_DIR' => $stateDir, 'LQCRON_TIMEOUT' => '0'],
	);
	$process->start();

	return $process;
};

$marker = static fn (string $uuid): array => json_decode((string) file_get_contents($stateDir . '/runs/' . $uuid . '.exit.json'), true);

// --- success: running + pid, run.started, coalesced progress, marker with the finishJob() data ---
$insertRun('run-ok', 'ok');
$process = $startExecute('run-ok', 'ok', ['count' => 5]);
$process->wait();
Assert::same(0, $process->getExitCode(), $process->getErrorOutput());
Assert::contains('handler output', $process->getOutput());

$run = schedulerRows($stateDir, "SELECT * FROM runs WHERE uuid = 'run-ok'")[0];
Assert::same('running', $run['state']); // the final state is lqcron's to write
Assert::true($run['pid'] > 0);
Assert::true($run['pid_start'] > 0);
Assert::same(realpath($stateDir), $run['app_path']);

$events = schedulerRows($stateDir, "SELECT type, payload FROM outbox WHERE run_uuid = 'run-ok' ORDER BY seq");
Assert::same(['run.started', 'run.progress', 'run.progress'], array_column($events, 'type'));
$started = json_decode($events[0]['payload'], true);
Assert::same('ok', $started['cron_code']);
Assert::same(2, $started['max_attempts']);
// The second progress came within 10 s: held back, flushed with the marker as the last value.
Assert::same(['step' => 2], json_decode($events[2]['payload'], true)['data']);
Assert::count(2, schedulerRows($stateDir, "SELECT * FROM run_events WHERE run_uuid = 'run-ok'"));

$okMarker = $marker('run-ok');
Assert::same('finished', $okMarker['status']);
Assert::same(['imported' => 5], $okMarker['result']);
Assert::null($okMarker['error']);

// --- data that is not valid JSON (invalid UTF-8, NaN) must not lose the marker of a successful run ---
$insertRun('run-binary', 'binary');
$process = $startExecute('run-binary', 'binary');
$process->wait();
Assert::same(0, $process->getExitCode(), $process->getErrorOutput());
$binary = $marker('run-binary');
Assert::same('finished', $binary['status']);
Assert::contains('binary', $binary['result']['bytes']);

// ...nor the error of a failed one; huge messages are capped.
$insertRun('run-binary-boom', 'binaryBoom');
$process = $startExecute('run-binary-boom', 'binaryBoom');
$process->wait();
$binaryBoom = $marker('run-binary-boom');
Assert::same('failed', $binaryBoom['status']);
Assert::contains('SQLSTATE: bad bytes', $binaryBoom['error']['message']);
Assert::true(strlen($binaryBoom['error']['message']) < 70000);
Assert::contains('truncated', $binaryBoom['error']['message']);

// --- exception: failed marker with message and trace ---
$insertRun('run-boom', 'boom');
$process = $startExecute('run-boom', 'boom');
$process->wait();
Assert::same(1, $process->getExitCode());
$boom = $marker('run-boom');
Assert::same('failed', $boom['status']);
Assert::same('RuntimeException', $boom['error']['class']);
Assert::same('handler boom', $boom['error']['message']);
Assert::contains('#0', $boom['error']['trace']);

// --- explicit failJob() wins over exit 0 ---
$insertRun('run-fail', 'explicitFail');
$process = $startExecute('run-fail', 'explicitFail');
$process->wait();
Assert::same(0, $process->getExitCode());
$fail = $marker('run-fail');
Assert::same('failed', $fail['status']);
Assert::true($fail['explicit_fail']);
Assert::same(['reason' => 'remote said no'], $fail['result']);

// --- not in `starting` (cancelled / reclaimed): the handler does not run, no marker ---
$insertRun('run-taken', 'ok', 'claimed');
$process = $startExecute('run-taken', 'ok');
$process->wait();
Assert::same(3, $process->getExitCode());
Assert::false(is_file($stateDir . '/runs/run-taken.exit.json'));
Assert::same([], schedulerRows($stateDir, "SELECT * FROM outbox WHERE run_uuid = 'run-taken'"));

// --- disk_low: progress is not stored, state events are ---
schedulerExec($stateDir, "INSERT INTO meta (key, value) VALUES ('disk_low', '1')");
$insertRun('run-low', 'ok');
$process = $startExecute('run-low', 'ok');
$process->wait();
Assert::same(['run.started'], array_column(schedulerRows($stateDir, "SELECT type FROM outbox WHERE run_uuid = 'run-low'"), 'type'));
schedulerExec($stateDir, "DELETE FROM meta WHERE key = 'disk_low'");

// --- SIGTERM: interrupt() gets its chance, marker records the termination ---
if (function_exists('pcntl_signal')) {
	$insertRun('run-sleepy', 'sleepy');
	$process = $startExecute('run-sleepy', 'sleepy');
	$deadline = microtime(true) + 10;

	while (microtime(true) < $deadline && (schedulerRows($stateDir, "SELECT state FROM runs WHERE uuid = 'run-sleepy'")[0]['state'] ?? '') !== 'running') {
		usleep(50000);
	}

	usleep(300000);
	$process->signal(SIGTERM);
	$process->wait();
	Assert::same(143, $process->getExitCode());
	Assert::true(is_file($stateDir . '/interrupted'));
	Assert::contains('SIGTERM', $marker('run-sleepy')['error']['message']);
}

echo "\nOK " . __FILE__ . "\n";
