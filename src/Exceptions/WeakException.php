<?php

namespace LiquidMonitorConnector\Exceptions;

use Throwable;

/**
 * Weak Exception is not-important error, which will not be sent to Slack
 */
class WeakException extends \Exception
{
	public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
	{
		if ($previous) {
			$message = $message ? "$message: " . $previous->getMessage() : $previous->getMessage();
			$code = $code ? "$code: " . $previous->getCode() : $previous->getCode();
		}

		parent::__construct($message, $code, $previous);
	}
}
