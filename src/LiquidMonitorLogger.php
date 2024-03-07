<?php

namespace LiquidMonitorConnector;

use Nette\Http\Request;
use Nette\Http\RequestFactory;
use Nette\Utils\Arrays;
use Nette\Utils\Json;
use Nette\Utils\Strings;
use Tracy\Debugger;
use Tracy\ILogger;
use Tracy\Logger;

class LiquidMonitorLogger extends Logger
{
	private const MAX_MESSAGE_LENGTH = 4850;

	private string $title;

	/**
	 * @var array<string>
	 */
	private array $levels;

	public function __construct(protected readonly Request $request, protected readonly Cron $cron, protected readonly RequestFactory $requestFactory)
	{
		parent::__construct(Debugger::$logDirectory, Debugger::$email, Debugger::getBlueScreen());
	}

	/**
	 * @param string $title
	 * @param array<string> $levels
	 */
	public function setProperties(string $title, array $levels): void
	{
		$this->title = $title;
		$this->levels = $levels;
	}

	public function log(mixed $message, string $level = \Tracy\ILogger::INFO): ?string
	{
		$result = parent::log($message, $level);

		if (!Arrays::contains($this->levels, $level)) {
			return $result;
		}

		[$message, $data] = $this->parseMessage($message);

		try {
			$this->sendToLogger($message, $level, $data);
		} catch (\Exception $e) {
			parent::log($e, ILogger::EXCEPTION);
		}

		return $result;
	}

	public function sendToLogger(string $message, string $level, string|null $data = null): void
	{
		$this->cron->log([
			'title' => $this->title,
			'url' => $this->request->getUrl(),
			'message' => $message,
			'data' => $data,
			'remoteAddress' => $this->request->getRemoteAddress(),
			'method' => $this->request->getMethod(),
			// phpcs:ignore
			'duration' => (int) ((\microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000),
			'memory_usage' => $this->getCurrentMemoryUsage(),
		], $level);
	}

	protected function getCurrentMemoryUsage(): int
	{
		return (int) (\memory_get_peak_usage() / 1000000);
	}

	/**
	 * @param mixed $message
	 * @return array{0: string, 1: string|null}
	 */
	private function parseMessage($message): array
	{
		$data = null;

		if ($message instanceof \Throwable) {
			$data = [
				'trace' => $message->getTrace(),
				'file' => $message->getFile(),
				'line' => $message->getLine(),
			];

			$message = $message->getMessage() . ' #' . $message->getCode();
		} elseif (\is_array($message)) {
			$data = $message;
			$message = (string) Arrays::first($message);
		} else {
			$message = (string) $message;
		}

		if (Strings::length($message) > self::MAX_MESSAGE_LENGTH) {
			$message = Strings::substring($message, 0, self::MAX_MESSAGE_LENGTH);
		}

		return [$message, $data ? Json::encode($data) : null];
	}
}
