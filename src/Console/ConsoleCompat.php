<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Registrace commandu napříč celým rozsahem symfony/console, který connector povoluje
 * (^6.3 || ^7.0 || ^8.0).
 *
 * Application::addCommand() existuje až od 7.4 (a add() je tam deprecated), v 8.0 je add()
 * odstraněné — ani jedna z metod tedy celý rozsah sama nepokryje. Hosti na StORM 1 /
 * composer/composer 2.5 drží symfony/console na 6.x.
 */
final class ConsoleCompat
{
	public static function addCommand(Application $application, Command $command): void
	{
		// Novější API napřed; add() jen pro symfony/console < 7.4.
		foreach (['addCommand', 'add'] as $method) {
			if (\method_exists($application, $method)) {
				$application->{$method}($command);

				return;
			}
		}

		throw new \LogicException('symfony/console Application has neither addCommand() nor add().');
	}
}
