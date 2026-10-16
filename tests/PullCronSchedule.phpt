<?php

declare(strict_types=1);

use LiquidMonitorConnector\Scheduler\CronDefinition;
use LiquidMonitorConnector\Scheduler\CronDefinitionCatalog;
use LiquidMonitorConnector\Worker\ConcurrencyModeType;
use LiquidMonitorConnector\Worker\PullCron;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// The attribute is the only source of a cron's schedule (§ 5.0): schedules
// override per environment, environments switch the cron off elsewhere.
$attribute = new PullCron(
	schedule: '*/30 * * * *',
	schedules: ['production' => '30 * * * *', 'test' => '15 */4 * * *'],
	environments: ['production', 'test'],
);

Assert::same('30 * * * *', $attribute->scheduleFor('production'));
Assert::same('schedules.production', $attribute->scheduleSourceFor('production'));
Assert::same('15 */4 * * *', $attribute->scheduleFor('test'));
Assert::null($attribute->scheduleFor('dev'));
Assert::same('environments', $attribute->scheduleSourceFor('dev'));
Assert::null($attribute->scheduleFor(null));

$everywhere = new PullCron(schedule: '0 3 * * *', schedules: ['test' => '50 0 * * *']);
Assert::same('0 3 * * *', $everywhere->scheduleFor('production'));
Assert::same('0 3 * * *', $everywhere->scheduleFor(null));
Assert::same('schedule', $everywhere->scheduleSourceFor('production'));
Assert::same('50 0 * * *', $everywhere->scheduleFor('test'));

// Concurrency mode is a plain string constant now (PHP 8.0, connector 4.0).
Assert::same('independent', ConcurrencyModeType::Independent);
Assert::same('fully_parallel', ConcurrencyModeType::from('fully_parallel'));
Assert::null(ConcurrencyModeType::tryFrom('bogus'));
Assert::exception(static fn () => new PullCron(schedule: '* * * * *', concurrencyMode: 'bogus'), ValueError::class);
Assert::exception(static fn () => new PullCron(schedule: '* * * * *', missed: 'twice'), InvalidArgumentException::class);

// isDue evaluates the minute in its own timezone.
$definition = new CronDefinition('mergadoPrices', PullCronScheduleTestHandler::class, $attribute, 'production');
$prague = new DateTimeZone('Europe/Prague');
Assert::true($definition->isDue(new DateTimeImmutable('2026-10-16 10:30:00', $prague)));
Assert::false($definition->isDue(new DateTimeImmutable('2026-10-16 10:00:00', $prague)));
Assert::same('exclusive', $definition->concurrencyMode());
Assert::same(10, $definition->maxQueueSize());

$off = new CronDefinition('mergadoPrices', PullCronScheduleTestHandler::class, $attribute, 'dev');
Assert::false($off->isDue(new DateTimeImmutable('2026-10-16 10:30:00', $prague)));

// Several expressions when one cannot describe the schedule (ABEL ClearImportData: 13:00 and 23:15).
$twice = new PullCron(schedule: ['0 13 * * *', '15 23 * * *'], schedules: ['test' => ['0 13 * * *', '5 0 * * *']]);
Assert::same('0 13 * * * | 15 23 * * *', $twice->scheduleFor('production'));
Assert::same(['0 13 * * *', '5 0 * * *'], $twice->expressionsFor('test'));
Assert::same(['0 13 * * *', '15 23 * * *', '5 0 * * *'], $twice->allExpressions());
$twiceDefinition = new CronDefinition('clearImportData', PullCronScheduleTestHandler::class, $twice, 'production');
Assert::true($twiceDefinition->isDue(new DateTimeImmutable('2026-10-16 13:00:00', $prague)));
Assert::true($twiceDefinition->isDue(new DateTimeImmutable('2026-10-16 23:15:00', $prague)));
Assert::false($twiceDefinition->isDue(new DateTimeImmutable('2026-10-16 23:00:00', $prague)));
Assert::same(['0 13 * * *', '15 23 * * *'], PullCron::normalizeExpressions('0 13 * * * | 15 23 * * *'));

// environments: [] = scheduled nowhere, on demand only (a handler that must not start by itself).
$nowhere = new PullCron(schedule: '* * * * *', environments: []);
Assert::null($nowhere->scheduleFor('production'));
Assert::same('environments', $nowhere->scheduleSourceFor('production'));

// The catalog reads attributes without instantiating handlers and skips classes without one.
$catalog = new CronDefinitionCatalog([
	'pullCronScheduleTest' => PullCronScheduleTestHandler::class,
	'plain' => stdClass::class,
], 'test');
Assert::same(['pullCronScheduleTest'], array_keys($catalog->all()));
Assert::same('15 */4 * * *', $catalog->get('pullCronScheduleTest')?->schedule());
Assert::same('30 * * * *', $catalog->all('production')['pullCronScheduleTest']->schedule());
Assert::true($catalog->has('pullCronScheduleTest'));
Assert::false($catalog->has('plain'));
Assert::same('pullCronScheduleTest', $catalog->codeOf(PullCronScheduleTestHandler::class));

#[PullCron(
	schedule: '*/30 * * * *',
	schedules: ['production' => '30 * * * *', 'test' => '15 */4 * * *'],
	environments: ['production', 'test'],
)]
final class PullCronScheduleTestHandler
{
	public function __construct()
	{
		throw new LogicException('The catalog must not instantiate handlers.');
	}
}

echo "\nOK " . __FILE__ . "\n";
