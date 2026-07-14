<?php

declare(strict_types=1);

use LiquidMonitorConnector\Cron;
use LiquidMonitorConnector\Worker\ConcurrencyModeType;
use LiquidMonitorConnector\Worker\PullCron;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Tester\Assert;
use Tracy\Debugger;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// Cron::schedulePullJob() logs via Tracy\Debugger::log(); give it a log directory.
Debugger::$logDirectory = \sys_get_temp_dir();

#[PullCron(
	schedule: '*/5 * * * *',
	name: 'Import prices',
	repeatCount: 2,
	concurrencyMode: ConcurrencyModeType::Independent,
	description: 'Updates floating prices',
	timeout: 300,
	maxQueueSize: 5,
)]
final class UpdateCkpFloatingPricesHandler
{
}

final class NoAttributeHandler
{
}

/**
 * Test double capturing the payload instead of performing a real HTTP call.
 */
final class RecordingCron extends Cron
{
	/** @var array<string, mixed>|null */
	public array|null $lastParams = null;

	public string|null $lastUrl = null;

	protected function send(string $url, string|null $apiKey, array $params, bool $throw = false): void
	{
		$this->lastUrl = $url;
		$this->lastParams = $params;
	}
}

$makeCron = static fn (): RecordingCron => new RecordingCron(new Request(new UrlScript('http://localhost/')));

// --- #[PullCron] attribute is read and mapped into the schedule-job payload. ---
$cron = $makeCron();
$cron->setConfiguration('https://v1/api_connector', 'KEY1', true);
$cron->schedulePullJob(UpdateCkpFloatingPricesHandler::class, ['userId' => 7]);

Assert::same('https://v1/api_connector/schedule-job', $cron->lastUrl);
Assert::notNull($cron->lastParams);
Assert::same('updateCkpFloatingPrices', $cron->lastParams['cronId']);
Assert::same('pull', $cron->lastParams['executionMode']);
Assert::same('*/5 * * * *', $cron->lastParams['cronTiming']);
Assert::same('Import prices', $cron->lastParams['cronName']);
Assert::same(2, $cron->lastParams['cronRepeatCount']);
Assert::same('independent', $cron->lastParams['cronConcurrencyMode']);
Assert::same('Updates floating prices', $cron->lastParams['cronDescription']);
Assert::same(300, $cron->lastParams['cronTimeout']);
Assert::same(5, $cron->lastParams['cronMaxQueueSize']);
Assert::true($cron->lastParams['createIfNotExists']);
Assert::same(['userId' => 7], $cron->lastParams['arguments']);
Assert::false(\array_key_exists('cronUrl', $cron->lastParams));
Assert::false(\array_key_exists('timeout', $cron->lastParams));

// --- Missing #[PullCron] attribute throws before any HTTP call is attempted. ---
$cron = $makeCron();
$cron->setConfiguration('https://v1/api_connector', 'KEY1', true);

Assert::exception(
	static fn () => $cron->schedulePullJob(NoAttributeHandler::class),
	InvalidArgumentException::class,
	'Cron job handler class "NoAttributeHandler" is missing the #[PullCron] attribute required by schedulePullJob().',
);
Assert::null($cron->lastParams);

echo "\nOK " . __FILE__ . "\n";
