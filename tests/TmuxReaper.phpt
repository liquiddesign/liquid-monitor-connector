<?php

declare(strict_types=1);

use LiquidMonitorConnector\Orchestrator\TmuxReaper;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$all = [
	'orch-p1-t10',   // this project, alive on monitor
	'orch-p1-t11',   // this project, orphan
	'orch-p2-t20',   // other project — never touched
	'orch-42',       // legacy naming — never touched
	'my-dev-session', // unrelated session — never touched
	'',
];

$whitelist = ['orch-p1-t10'];

Assert::same(['orch-p1-t11'], TmuxReaper::selectOrphans($all, $whitelist, 1));

// Other project's perspective: only its own orphans
Assert::same(['orch-p2-t20'], TmuxReaper::selectOrphans($all, [], 2));

// Everything whitelisted → nothing to reap
Assert::same([], TmuxReaper::selectOrphans($all, ['orch-p1-t10', 'orch-p1-t11'], 1));

// No sessions at all
Assert::same([], TmuxReaper::selectOrphans([], ['orch-p1-t10'], 1));

// Prefix must match exactly — p11 is not p1
Assert::same([], TmuxReaper::selectOrphans(['orch-p11-t5'], [], 1));

echo "\nOK " . __FILE__ . "\n";
