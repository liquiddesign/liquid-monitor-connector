<?php

declare(strict_types=1);

use GuzzleHttp\Exception\GuzzleException;
use LiquidMonitorConnector\Cron;
use LiquidMonitorConnector\Scheduler\CronDefinitionCatalog;
use LiquidMonitorConnector\Scheduler\Exceptions\QueueFullException;
use LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException;
use LiquidMonitorConnector\Scheduler\Exceptions\SchedulingFailedException;
use LiquidMonitorConnector\Scheduler\Exceptions\UnknownCronHandlerException;
use LiquidMonitorConnector\Scheduler\LocalQueue;
use LiquidMonitorConnector\Scheduler\SchedulerConfig;
use LiquidMonitorConnector\Worker\PullCron;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Tester\Assert;
use Tracy\Debugger;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/fixtures/scheduler.php';

Tester\Environment::setup();
Debugger::$logDirectory = \sys_get_temp_dir();

#[PullCron(schedule: '*/5 * * * *', maxQueueSize: 2)]
final class ImportOrdersHandler
{
}

final class NotACronHandler
{
}

$stateDir = schedulerStateDir('queue');
$catalog = new CronDefinitionCatalog(['importOrders' => ImportOrdersHandler::class], 'production');
$queue = new LocalQueue($stateDir, $catalog);

// --- enqueue writes the job and its job.queued event in one transaction ---
$uuid = $queue->enqueue(ImportOrdersHandler::class, ['orderId' => 7], 30);
$jobs = schedulerRows($stateDir, 'SELECT * FROM jobs');
Assert::count(1, $jobs);
Assert::same($uuid, $jobs[0]['uuid']);
Assert::same($uuid, $jobs[0]['origin_uuid']);
Assert::same('importOrders', $jobs[0]['cron_code']);
Assert::same('{"orderId":7}', $jobs[0]['arguments']);
Assert::same('app', $jobs[0]['source']);
Assert::same(1, $jobs[0]['attempt']);
Assert::true($jobs[0]['available_at'] - $jobs[0]['created_at'] === 30000);

$events = schedulerRows($stateDir, 'SELECT * FROM outbox ORDER BY seq');
Assert::count(1, $events);
Assert::same('job.queued', $events[0]['type']);
Assert::match('%a%-7%a%', $events[0]['event_id']);
$payload = json_decode($events[0]['payload'], true);
Assert::same($uuid, $payload['job_uuid']);
Assert::true($payload['has_arguments']);
Assert::false(isset($payload['arguments']));

// --- queue limit counts delayed jobs too; the rejection event survives the exception ---
$queue->enqueue(ImportOrdersHandler::class);
$exception = Assert::exception(static fn () => $queue->enqueue(ImportOrdersHandler::class), QueueFullException::class);
Assert::type(GuzzleException::class, $exception);
Assert::type(SchedulingFailedException::class, $exception);
Assert::same(2, $exception->queueSize);
Assert::count(2, schedulerRows($stateDir, 'SELECT * FROM jobs'));
$rejected = schedulerRows($stateDir, "SELECT * FROM outbox WHERE type = 'job.rejected'");
Assert::count(1, $rejected);
Assert::same('queue_full', json_decode($rejected[0]['payload'], true)['reason']);

// --- unknown handler ---
Assert::exception(static fn () => $queue->enqueue(NotACronHandler::class), UnknownCronHandlerException::class);

// --- isCronRunning: queued job or active run, nothing else ---
Assert::true($queue->isCronRunning('importOrders'));
Assert::false($queue->isCronRunning('other'));
schedulerExec($stateDir, "INSERT INTO runs (uuid, job_uuid, origin_uuid, cron_code, attempt, max_attempts, source, job_created_at, concurrency_mode, state, claimed_at)
	VALUES ('r1', 'j1', 'j1', 'other', 1, 1, 'schedule', 1, 'exclusive', 'running', 1)");
Assert::true($queue->isCronRunning('other'));
schedulerExec($stateDir, "UPDATE runs SET state = 'finished', finished_at = 1760000000000, started_at = 1759999990000, result = '{\"done\":1}' WHERE uuid = 'r1'");
Assert::false($queue->isCronRunning('other'));

// --- lastRun maps a local run to the job log shape ---
$last = $queue->lastRun('other');
Assert::same('finished', $last['type']);
Assert::same(['done' => 1], $last['data']);
Assert::same('local', $last['source']);
Assert::null($last['job_id']);
Assert::null($queue->lastRun('importOrders'));

// --- isCronDefined reads lqcron's last export ---
Assert::false($queue->isCronDefined('importOrders'));
schedulerExec($stateDir, "INSERT INTO crons (code, handler_class, updated_at) VALUES ('importOrders', 'ImportOrdersHandler', 1)");
Assert::true($queue->isCronDefined('importOrders'));

// --- Cron routes schedulePullJob / scheduleJob / isCronRunning to the local queue ---
$cron = new Cron(new Request(new UrlScript('http://localhost/')));
$cron->setConfiguration('http://127.0.0.1:9/api/connector', 'KEY', true);
$cron->setScheduler(new SchedulerConfig(enabled: true, stateDir: $stateDir), $catalog, new LocalQueue($stateDir, $catalog));
schedulerExec($stateDir, 'DELETE FROM jobs');
$cron->schedulePullJob(ImportOrdersHandler::class);
$cron->scheduleJob('importOrders');
Assert::count(2, schedulerRows($stateDir, "SELECT * FROM jobs WHERE cron_code = 'importOrders'"));
Assert::true($cron->isCronRunning('importOrders'));
Assert::true($cron->isCronDefined('importOrders'));
Assert::false($cron->isCronDefined('missing'));

// LQDeck down: the last job log falls back to the last local run.
Assert::same('finished', $cron->getLastCronJobLog('other')['type'] ?? null);

// --- a newer schema than this PHP knows: refuse to write (§ 12.1) ---
schedulerExec($stateDir, "UPDATE meta SET value = '2' WHERE key = 'min_reader_version'");
Assert::exception(static fn () => $queue->enqueue(ImportOrdersHandler::class), SchedulerUnavailableException::class, '%a%requires schema reader version 2%a%');

// --- LQCRON_PHP_SQLITE_DRIVER pins the driver (CI runs both) ---
putenv('LQCRON_PHP_SQLITE_DRIVER=sqlite3');
Assert::same('sqlite3', \LiquidMonitorConnector\Scheduler\SqliteConnection::configuredDriver());
putenv('LQCRON_PHP_SQLITE_DRIVER=bogus');
Assert::null(\LiquidMonitorConnector\Scheduler\SqliteConnection::configuredDriver());
putenv('LQCRON_PHP_SQLITE_DRIVER');

// --- PHP never creates the database ---
$missing = new LocalQueue(sys_get_temp_dir() . '/lqcron-missing-' . getmypid(), $catalog);
Assert::exception(static fn () => $missing->enqueue(ImportOrdersHandler::class), SchedulerUnavailableException::class, '%a%does not exist%a%');
Assert::false(is_file(sys_get_temp_dir() . '/lqcron-missing-' . getmypid() . '/lqcron.sqlite'));

echo "\nOK " . __FILE__ . "\n";
