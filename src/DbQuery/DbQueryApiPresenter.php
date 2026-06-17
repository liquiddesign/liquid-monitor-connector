<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\DbQuery;

use InvalidArgumentException;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\UI\Presenter;
use Nette\Utils\Strings;
use PDOException;
use Tracy\Debugger;

/**
 * JSON REST API for read-only database queries proxied through the connector.
 *
 * Access control:
 *  1. Trusted IP — Tracy debug mode (Debugger::$productionMode === false), i.e. the same
 *     per-IP gate the host app uses for the log viewer (Configurator::setDebugMode with the
 *     access.debug IP whitelist). Only access.debug is consulted here — access.trusted (which
 *     the host app may use for cron/other access) is NOT evaluated, so a caller whose IP is only
 *     in access.trusted still gets 403. The database connection credentials themselves are supplied
 *     by the caller (the monitor) in the request body, so no separate token is required.
 *  2. Optional apiToken from DI — when configured, additionally requires a matching X-Api-Key
 *     header. A trusted IP alone is sufficient when no apiToken is set.
 */
class DbQueryApiPresenter extends Presenter
{
	public function __construct(private readonly DbQueryConfig $config)
	{
	}

	public function actionQuery(): void
	{
		if ($this->getHttpRequest()->getMethod() !== 'POST') {
			$this->getHttpResponse()->addHeader('Allow', 'POST');
			$this->sendErrorResponse(405, 'Method not allowed');
		}

		/** @var mixed $decoded */
		$decoded = \json_decode($this->getHttpRequest()->getRawBody() ?? '', true);

		if (!\is_array($decoded)) {
			$this->sendErrorResponse(400, 'Invalid JSON body.');
		}

		$sql = $decoded['sql'] ?? null;

		if (!\is_string($sql) || Strings::trim($sql) === '') {
			$this->sendErrorResponse(422, 'Missing or empty "sql" field.');
		}

		/** @var mixed $rawConnection */
		$rawConnection = $decoded['connection'] ?? null;

		if (!\is_array($rawConnection)) {
			$this->sendErrorResponse(422, 'Missing "connection" object.');
		}

		/** @var array{driver: string, host: string, port?: int|null, database: string, username: string, password?: string|null} $connection */
		$connection = $rawConnection;
		$this->assertValidConnection($connection);

		$rowLimit = self::intOrDefault($decoded['row_limit'] ?? null, 100);
		$statementTimeout = self::intOrDefault($decoded['statement_timeout_seconds'] ?? null, 5);

		try {
			$runner = new ReadOnlyQueryRunner($connection, $rowLimit, $statementTimeout);
			$result = $runner->run($sql);
		} catch (InvalidArgumentException $e) {
			$this->sendErrorResponse(422, $e->getMessage());
		} catch (PDOException $e) {
			$this->sendErrorResponse(500, 'Database query failed.');
		}

		$this->sendJsonPayload($result);
	}

	/**
	 * Whether the request is served from a trusted IP, i.e. the host app runs in Tracy debug
	 * mode for this client (Debugger::$productionMode === false).
	 *
	 * NB: Debugger::isEnabled() must NOT be used for this — it merely reports that Tracy was
	 * activated and stays true in production, so it would never block anyone. Only an explicit
	 * $productionMode === false counts as debug mode; anything else (true, or the null Detect
	 * default before bootstrap) fails closed.
	 */
	public static function isTrustedDebugMode(?bool $productionMode): bool
	{
		return $productionMode === false;
	}

	protected function startup(): void
	{
		parent::startup();

		// Trusted-IP gate: serve only in Tracy debug mode (per-IP via the host's
		// Configurator::setDebugMode IP whitelist).
		if (!self::isTrustedDebugMode(Debugger::$productionMode)) {
			$this->sendErrorResponse(403, 'Access denied');
		}

		if ($this->config->apiToken === null || $this->config->apiToken === '') {
			return;
		}

		$provided = $this->getHttpRequest()->getHeader('X-Api-Key') ?? '';

		if (\hash_equals($this->config->apiToken, $provided)) {
			return;
		}

		$this->sendErrorResponse(403, 'Invalid API key');
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	protected function sendJsonPayload(array $payload, int $code = 200): never
	{
		$this->getHttpResponse()->setCode($code);
		$this->sendResponse(new JsonResponse($payload));
	}

	protected function sendErrorResponse(int $code, string $message): never
	{
		$this->sendJsonPayload(['error' => $message, 'code' => $code], $code);
	}

	/**
	 * @param array<string, mixed> $connection
	 */
	private function assertValidConnection(array $connection): void
	{
		foreach (['host', 'database', 'username', 'driver'] as $field) {
			$value = $connection[$field] ?? null;

			if (\is_string($value) && Strings::trim($value) !== '') {
				continue;
			}

			$this->sendErrorResponse(422, "Missing required connection field: {$field}.");
		}
	}

	private static function intOrDefault(mixed $value, int $default): int
	{
		if (\is_int($value)) {
			return $value;
		}

		if (\is_string($value) && \ctype_digit($value)) {
			return (int) $value;
		}

		return $default;
	}
}
