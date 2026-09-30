<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry;

use Nette\Application\Application;
use Nette\Application\BadRequestException;
use Nette\Application\Request;

/**
 * Napojí recorder na eventy `Nette\Application` — funguje v každém Nette projektu bez kódu navíc.
 *
 * Fáze: `boot` (start PHP → vytvoření Application, tedy bootstrap + DI), `startup` (routing),
 * `presenter` (akce presenteru až po vrácení odpovědi), `send` (odeslání odpovědi — u
 * `TemplateResponse` včetně renderu šablony).
 */
final class NetteApplicationBridge
{
	public static function attach(Application $application, TelemetryConfig $config): void
	{
		if (!$config->isActiveForCurrentSapi()) {
			return;
		}

		Recorder::start($config);
		Recorder::mark('startup');

		$application->onRequest[] = static function (Application $application, Request $request): void {
			Recorder::mark('presenter', true);
			$action = $request->getParameter('action');
			Recorder::setRoute($request->getPresenterName() . ':' . (\is_string($action) ? $action : 'default'));
		};

		$application->onResponse[] = static function (): void {
			Recorder::mark('send');
		};

		$application->onError[] = static function (Application $application, \Throwable $error): void {
			// 404 a spol. nejsou chyba aplikace, jen status
			if ($error instanceof BadRequestException && $error->getHttpCode() < 500) {
				return;
			}

			Recorder::markError($error::class);
		};

		$application->onShutdown[] = static function (): void {
			Recorder::flush();
		};
	}
}
