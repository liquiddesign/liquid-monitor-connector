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
		$previousExceptions = [];
		$exceptionCopy = $exception;

		while ($exceptionCopy = $exceptionCopy->getPrevious()) {
			$previousExceptions[] = [
				'message' => $exceptionCopy->getMessage(),
				'trace' => self::getTraces($exceptionCopy),
				'file' => $exceptionCopy->getFile(),
				'line' => $exceptionCopy->getLine(),
			];
		}

		return [
			'message' => $exception->getMessage(),
			'trace' => self::getTraces($exception),
			'file' => $exception->getFile(),
			'line' => $exception->getLine(),
			'previousExceptions' => $previousExceptions,
		];
	}
}
