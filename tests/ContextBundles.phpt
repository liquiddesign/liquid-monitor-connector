<?php

declare(strict_types=1);

use LiquidMonitorConnector\Orchestrator\ContextBundles;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$bundles = new ContextBundles();

Assert::same(
	['env' => [], 'add_dirs' => [], 'allowed_tools' => []],
	$bundles->merge([]),
);

$merged = $bundles->merge([
	[
		'type' => 'logs',
		'env' => ['TRIAGE_LOG_PATH' => '/var/log/app', 'TRIAGE_LOG_GLOB' => '', 'BAD' => 42],
		'add_dirs' => ['/var/log/app'],
		'allowed_tools_patterns' => ['Read', 'Grep'],
	],
	[
		'type' => 'errors_api',
		'env' => ['MONITOR_API_URL' => 'https://monitor.test'],
		'add_dirs' => ['/var/log/app'],
		'allowed_tools_patterns' => ['Bash(curl * https://monitor.test/api/context/errors*)', 'Read'],
	],
	[
		'type' => 'broken',
		'env' => 'not-an-array',
	],
]);

Assert::same(
	['TRIAGE_LOG_PATH' => '/var/log/app', 'MONITOR_API_URL' => 'https://monitor.test'],
	$merged['env'],
);
Assert::same(['/var/log/app'], $merged['add_dirs']);
Assert::same(
	['Read', 'Grep', 'Bash(curl * https://monitor.test/api/context/errors*)'],
	$merged['allowed_tools'],
);

// With a monitor URL + API key, both land in every agent env regardless of sources.
$withAuth = new ContextBundles('https://monitor.test/', 'trk_secret');
$mergedAuth = $withAuth->merge([]);
Assert::same(
	['MONITOR_API_URL' => 'https://monitor.test', 'MONITOR_TRIAGE_API_KEY' => 'trk_secret'],
	$mergedAuth['env'],
);

echo "\nOK " . __FILE__ . "\n";
