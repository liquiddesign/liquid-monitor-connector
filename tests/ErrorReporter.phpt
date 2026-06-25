<?php

declare(strict_types=1);

use LiquidMonitorConnector\ErrorReporter;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

// --- Konfigurace kanálu přes gettery. ---
$reporter = new ErrorReporter(new Request(new UrlScript('http://localhost/')));
$reporter->setConfiguration('https://mon/api_connector', 'KEY', true);

Assert::same('https://mon/api_connector', $reporter->getUrl());
Assert::same('KEY', $reporter->getApiKey());
Assert::true($reporter->isEnabled());

// --- Bez příchozího těla (běžný request) není jobId. ---
Assert::null($reporter->getJobId());

// --- jobId z monitor requestu se přečte z těla (provázání chyby s job logem). ---
$withJob = new ErrorReporter(new Request(new UrlScript('http://localhost/'), rawBodyCallback: static fn (): string => '{"jobId":123}'));
Assert::same('123', $withJob->getJobId());

// --- enabled:false projde do getteru. ---
$disabled = new ErrorReporter(new Request(new UrlScript('http://localhost/')));
$disabled->setConfiguration('https://mon/api_connector', null, false);
Assert::false($disabled->isEnabled());
Assert::null($disabled->getApiKey());

echo "\nOK " . __FILE__ . "\n";
