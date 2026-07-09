<?php

declare(strict_types=1);

use LiquidMonitorConnector\ErrorReporter;
use LiquidMonitorConnector\LiquidMonitorLogger;
use Nette\Http\Request;
use Nette\Http\RequestFactory;
use Nette\Http\UrlScript;
use Nette\Security\IIdentity;
use Nette\Security\User;
use Nette\Security\UserStorage;
use Tester\Assert;
use Tracy\ILogger;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

/**
 * ErrorReporter, který místo reálného HTTP volání na monitor jen zachytí
 * payload předaný z LiquidMonitorLogger::sendToLogger().
 */
class CapturingErrorReporter extends ErrorReporter
{
	/** @var array<string, mixed>|null */
	public array|null $capturedData = null;

	public string|null $capturedLevel = null;

	public function log(array $data, string $level): void
	{
		$this->capturedData = $data;
		$this->capturedLevel = $level;
	}
}

// --- Nepřihlášený uživatel (identita pro connector_type nehraje roli). ---
$storage = new class implements UserStorage {
	public function saveAuthentication(IIdentity $identity): void
	{
	}

	public function clearAuthentication(bool $clearIdentity): void
	{
	}

	/**
	 * @return array{bool, ?IIdentity, ?int}
	 */
	public function getState(): array
	{
		return [false, null, null];
	}

	public function setExpiration(?string $expire, bool $clearIdentity): void
	{
	}
};

$reporter = new CapturingErrorReporter(new Request(new UrlScript('http://localhost/')));

$logger = new LiquidMonitorLogger(
	new Request(new UrlScript('http://localhost/')),
	$reporter,
	new RequestFactory(),
	new User($storage),
);

// --- sendToLogger() musí do payloadu vždy přidat connector_type => 'nette'. ---
$logger->sendToLogger('Test message', ILogger::ERROR);

Assert::notNull($reporter->capturedData);
Assert::same('nette', $reporter->capturedData['connector_type']);
Assert::same(ILogger::ERROR, $reporter->capturedLevel);

echo "\nOK " . __FILE__ . "\n";
