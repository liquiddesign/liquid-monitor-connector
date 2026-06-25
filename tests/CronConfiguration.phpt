<?php

declare(strict_types=1);

use LiquidMonitorConnector\Cron;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

$makeCron = static fn (): Cron => new Cron(new Request(new UrlScript('http://localhost/')));

// --- 3-arg (legacy) volání: log kanál spadne na cronový. ---
$cron = $makeCron();
$cron->setConfiguration('https://v1/api_connector', 'KEY1', true);

Assert::same('https://v1/api_connector', $cron->getUrl());
Assert::same('KEY1', $cron->getApiKey());
Assert::same('https://v1/api_connector', $cron->getLogUrl());
Assert::same('KEY1', $cron->getLogApiKey());
Assert::true($cron->isEnabled());

// --- 5-arg volání: každý kanál vlastní url + apiKey. ---
$cron = $makeCron();
$cron->setConfiguration('https://v1/api_connector', 'KEY1', true, 'https://v2/api_connector', 'KEY2');

Assert::same('https://v1/api_connector', $cron->getUrl());
Assert::same('KEY1', $cron->getApiKey());
Assert::same('https://v2/api_connector', $cron->getLogUrl());
Assert::same('KEY2', $cron->getLogApiKey());

// --- Explicitní null v log argumentech → fallback na cronový kanál. ---
$cron = $makeCron();
$cron->setConfiguration('https://v1/api_connector', 'KEY1', false, null, null);

Assert::same('https://v1/api_connector', $cron->getLogUrl());
Assert::same('KEY1', $cron->getLogApiKey());
Assert::false($cron->isEnabled());

echo "\nOK " . __FILE__ . "\n";
