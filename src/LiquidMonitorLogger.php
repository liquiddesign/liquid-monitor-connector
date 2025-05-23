<?php

namespace LiquidMonitorConnector;

use LiquidMonitorConnector\Exceptions\WeakException;
use LiquidMonitorConnector\Tasks\ExceptionToJsonArray;
use Nette\Http\Request;
use Nette\Http\RequestFactory;
use Nette\Security\User;
use Nette\Utils\Arrays;
use Nette\Utils\Json;
use Nette\Utils\Strings;
use StORM\Entity;
use Tracy\Debugger;
use Tracy\ILogger;
use Tracy\Logger;

class LiquidMonitorLogger extends Logger
{
	private const MAX_MESSAGE_LENGTH = 4850;

	private string|null $title;

	/**
	 * @var array<string>
	 */
	private array $levels;

	public function __construct(protected Request $request, protected Cron $cron, protected RequestFactory $requestFactory, private readonly User $user)
	{
		parent::__construct(Debugger::$logDirectory, Debugger::$email, Debugger::getBlueScreen());
	}

	/**
	 * @param string $title
	 * @param array<string> $levels
	 */
	public function setProperties(string|null $title, array $levels): void
	{
		$this->title = $title;
		$this->levels = $levels;
	}

	public function log(mixed $message, string $level = \Tracy\ILogger::INFO, bool $weak = false): ?string
	{
		$result = parent::log($message, $level);

		if (!Arrays::contains($this->levels, $level)) {
			return $result;
		}

		if ($message instanceof WeakException) {
			$weak = true;
		}

		[$message, $data, $code] = $this->parseMessage($message);

		try {
			$this->sendToLogger($message, $level, $data, $code, $weak);
		} catch (\Exception $e) {
			parent::log($e, ILogger::CRITICAL);
		}

		return $result;
	}

	public function sendToLogger(string $message, string $level, string|null $data = null, string|int|null $code = null, bool $weak = false): void
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
			'code' => (string) $code,
			'weak' => $weak,
			'job_id' => $this->cron->getJobId(),
			'identity' => $this->user->isLoggedIn() && ($identity = $this->user->getIdentity()) ?
				Json::encode($identity instanceof Entity ? $identity->toArray() : (array) $identity) :
				null,
		], $level);
	}

	protected function getCurrentMemoryUsage(): int
	{
		return (int) (\memory_get_peak_usage() / 1000000);
	}

	/**
	 * @param mixed $message
	 * @return array{0: string, 1: string|null, 2: string|int|null}
	 */
	private function parseMessage(mixed $message): array
	{
		$code = null;

		if ($message instanceof \Throwable) {
			$data = ExceptionToJsonArray::getArray($message);

			$code = $message->getCode();
			$message = $message->getMessage();
		} elseif (\is_array($message)) {
			$trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);

			$data = [
				'message' => $message,
				'trace' => $trace,
			];

			$message = (string) Arrays::first($message);
		} else {
			$trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);

			$data = [
				'trace' => $trace,
			];

			$message = (string) $message;
		}

		if (Strings::length($message) > self::MAX_MESSAGE_LENGTH) {
			$message = Strings::substring($message, 0, self::MAX_MESSAGE_LENGTH);
		}

		try {
			$data = Json::encode($data);
		} catch (\Exception) {
			$data = null;
		}

		return [$message, $data, $code];
	}
}
