<?php

namespace LiquidMonitorConnector\Tasks;

use Nette\Utils\Strings;

class ExceptionToJsonArray
{
	/**
	 * @param \Throwable $exception
	 * @return array<string>
	 */
	public static function getTraces(\Throwable $exception): array
	{
		$traceString = $exception->getTraceAsString();
		$traces = \explode("\n", $traceString);
		$jsonTraces = [];

		foreach ($traces as $trace) {
			$jsonTraces[] = Strings::trim($trace);
		}

		return $jsonTraces;
	}

	/**
	 * @param \Throwable $exception
	 * @return array{
	 *     trace: array<string>,
	 *     file: string,
	 *     line: int
	 * }
	 */
	public static function getArray(\Throwable $exception): array
	{
		return [
			'trace' => self::getTraces($exception),
			'file' => $exception->getFile(),
			'line' => $exception->getLine(),
		];
	}
}
