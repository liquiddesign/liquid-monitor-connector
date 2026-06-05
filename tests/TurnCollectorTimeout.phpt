<?php

declare(strict_types=1);

use LiquidMonitorConnector\Orchestrator\TurnCollector;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$at = static fn (int $tsOffset): string => (new DateTimeImmutable())->modify($tsOffset . ' seconds')->format(DATE_ATOM);

$now = (new DateTimeImmutable())->getTimestamp();
$timeout = 900;

// Just submitted, no progress yet → not timed out.
Assert::false(TurnCollector::turnTimedOut(['submitted_at' => $at(-10)], $now, $timeout));

// Submitted long ago but NO progress field → measured from submitted_at → timed out.
Assert::true(TurnCollector::turnTimedOut(['submitted_at' => $at(-1000)], $now, $timeout));

// Submitted long ago BUT progressed recently → inactivity is short → NOT timed out.
// This is the regression: a long, actively-working turn must survive.
Assert::false(TurnCollector::turnTimedOut(
	['submitted_at' => $at(-5000), 'progress_at' => $at(-30)],
	$now,
	$timeout,
));

// Progressed, then went idle past the timeout → timed out.
Assert::true(TurnCollector::turnTimedOut(
	['submitted_at' => $at(-5000), 'progress_at' => $at(-1000)],
	$now,
	$timeout,
));

// Exactly at the boundary counts as timed out.
Assert::true(TurnCollector::turnTimedOut(['progress_at' => $at(-$timeout)], $now, $timeout));

// No parseable timestamps → never time out (fail safe; dead tmux handled elsewhere).
Assert::false(TurnCollector::turnTimedOut([], $now, $timeout));
Assert::false(TurnCollector::turnTimedOut(['submitted_at' => 'garbage'], $now, $timeout));

echo "\nOK " . __FILE__ . "\n";
