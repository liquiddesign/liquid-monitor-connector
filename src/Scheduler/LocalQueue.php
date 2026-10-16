<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

use LiquidMonitorConnector\Scheduler\Exceptions\QueueFullException;
use LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException;
use LiquidMonitorConnector\Scheduler\Exceptions\SchedulingFailedException;
use LiquidMonitorConnector\Scheduler\Exceptions\UnknownCronHandlerException;
use Nette\Utils\Json;

/**
 * @phpstan-import-type JobLogArray from \LiquidMonitorConnector\Cron
 */
final class LocalQueue
{
	public const ACTIVE_STATES = ['claimed', 'starting', 'running', 'stopping'];

	public const SOURCE_APP = 'app';

	public function __construct(private string $stateDir, private CronDefinitionCatalog $catalog)
	{
	}

	/**
	 * @param class-string $handlerClass
	 * @param array<mixed>|null $arguments
	 * @return string uuid of the queued job
	 * @throws \LiquidMonitorConnector\Scheduler\Exceptions\SchedulingFailedException
	 */
	public function enqueue(string $handlerClass, array|null $arguments = null, int|null $delaySeconds = null, string $source = self::SOURCE_APP): string
	{
		$definition = $this->definitionOf($handlerClass);
		$connection = SchedulerDatabase::openForWrite($this->stateDir);

		try {
			/** @var array{string|null, int, int} $outcome */
			$outcome = $connection->transaction(function (SqliteConnection $connection) use ($definition, $arguments, $delaySeconds, $source): array {
				$now = Clock::nowMs();
				$queueSize = (int) $connection->fetchValue('SELECT COUNT(*) FROM jobs WHERE cron_code = ?', [$definition->code]);
				$maxQueueSize = $definition->maxQueueSize();

				if ($queueSize >= $maxQueueSize) {
					EventOutbox::insert($connection, 'job.rejected', [
						'cron_code' => $definition->code,
						'source' => $source,
						'reason' => 'queue_full',
						'queue_size' => $queueSize,
						'max_queue_size' => $maxQueueSize,
					], null, $now);

					// The rejection event must survive the exception, so it is thrown
					// only after COMMIT.
					return [null, $queueSize, $maxQueueSize];
				}

				$uuid = Uuid::v7($now);
				$availableAt = $now + \max(0, $delaySeconds ?? 0) * 1000;

				$connection->execute(
					'INSERT INTO jobs (uuid, origin_uuid, cron_code, arguments, attempt, source, scheduled_for, available_at, created_at)
					VALUES (?, ?, ?, ?, 1, ?, NULL, ?, ?)',
					[$uuid, $uuid, $definition->code, $arguments === null ? null : Json::encode($arguments), $source, $availableAt, $now],
				);

				EventOutbox::insert($connection, 'job.queued', [
					'job_uuid' => $uuid,
					'origin_uuid' => $uuid,
					'cron_code' => $definition->code,
					'source' => $source,
					'attempt' => 1,
					'available_at' => Clock::iso($availableAt),
					'has_arguments' => $arguments !== null,
				], null, $now);

				return [$uuid, $queueSize, $maxQueueSize];
			});
		} catch (SchedulingFailedException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new SchedulerUnavailableException(\sprintf('Cannot enqueue cron "%s" into the local scheduler: %s', $definition->code, $e->getMessage()), 0, $e);
		} finally {
			$connection->close();
		}

		[$uuid, $queueSize, $maxQueueSize] = $outcome;

		if ($uuid === null) {
			throw new QueueFullException($definition->code, $queueSize, $maxQueueSize);
		}

		return $uuid;
	}

	/**
	 * Same meaning as the v3 `is-running` endpoint: an active run **or** a job
	 * waiting in the queue.
	 * @throws \LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException
	 */
	public function isCronRunning(string $cronCode): bool
	{
		$connection = SchedulerDatabase::openReadOnly($this->stateDir);

		try {
			$placeholders = \implode(', ', \array_fill(0, \count(self::ACTIVE_STATES), '?'));
			$running = $connection->fetchValue(
				"SELECT EXISTS (SELECT 1 FROM runs WHERE cron_code = ? AND state IN ($placeholders))
					OR EXISTS (SELECT 1 FROM jobs WHERE cron_code = ?)",
				[$cronCode, ...self::ACTIVE_STATES, $cronCode],
			);

			return (bool) $running;
		} finally {
			$connection->close();
		}
	}

	/**
	 * Whether the scheduler knows the cron from its last export.
	 * @throws \LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException
	 */
	public function isCronDefined(string $cronCode): bool
	{
		$connection = SchedulerDatabase::openReadOnly($this->stateDir);

		try {
			return (bool) $connection->fetchValue('SELECT EXISTS (SELECT 1 FROM crons WHERE code = ? AND defined = 1)', [$cronCode]);
		} finally {
			$connection->close();
		}
	}

	/**
	 * Last finished local run of a cron, shaped like the monitor's job log
	 * (fallback for {@see \LiquidMonitorConnector\Cron::getLastCronJobLog()} while LQDeck is down).
	 * @return JobLogArray|null
	 * @throws \LiquidMonitorConnector\Scheduler\Exceptions\SchedulerUnavailableException
	 */
	public function lastRun(string $cronCode): array|null
	{
		$connection = SchedulerDatabase::openReadOnly($this->stateDir);

		try {
			$placeholders = \implode(', ', \array_fill(0, \count(self::ACTIVE_STATES), '?'));
			$row = $connection->fetchOne(
				"SELECT * FROM runs WHERE cron_code = ? AND state NOT IN ($placeholders) ORDER BY id DESC LIMIT 1",
				[$cronCode, ...self::ACTIVE_STATES],
			);
		} finally {
			$connection->close();
		}

		if ($row === null) {
			return null;
		}

		$state = \is_string($row['state']) ? $row['state'] : 'failed';
		$result = \is_string($row['result']) ? Json::decode($row['result'], Json::FORCE_ARRAY) : null;

		return [
			'id' => (int) $row['id'],
			'uuid' => (string) $row['uuid'],
			'data' => $result,
			'type' => $state === 'finished' ? 'finished' : 'failed',
			'outcome' => $state,
			'created_at' => self::isoFromMs($row['claimed_at']) ?? '',
			'updated_at' => self::isoFromMs($row['finished_at'] ?? $row['claimed_at']) ?? '',
			'cron_id' => null,
			'job_id' => null,
			'started_ts' => self::isoFromMs($row['started_at']),
			'finished_ts' => self::isoFromMs($row['finished_at']),
			'repeatCount' => (int) $row['max_attempts'] - 1,
			'timeout' => $row['timeout'] === null ? null : (int) $row['timeout'],
			'source' => 'local',
		];
	}

	/**
	 * @param class-string $handlerClass
	 * @throws \LiquidMonitorConnector\Scheduler\Exceptions\UnknownCronHandlerException
	 */
	private function definitionOf(string $handlerClass): CronDefinition
	{
		$attribute = CronDefinitionCatalog::attributeOf($handlerClass);

		if ($attribute === null) {
			throw new UnknownCronHandlerException(\sprintf(
				'Cron job handler class "%s" is missing the #[PullCron] attribute required by schedulePullJob().',
				$handlerClass,
			));
		}

		$code = $this->catalog->codeOf($handlerClass);

		if ($code === null) {
			throw new UnknownCronHandlerException(\sprintf('Class "%s" is not a cron job handler (the name must end with "Handler").', $handlerClass));
		}

		return new CronDefinition($code, $handlerClass, $attribute, $this->catalog->environment());
	}

	private static function isoFromMs(mixed $milliseconds): string|null
	{
		return \is_numeric($milliseconds) ? Clock::iso((int) $milliseconds) : null;
	}
}
