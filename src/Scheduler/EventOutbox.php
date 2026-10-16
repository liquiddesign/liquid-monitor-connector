<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Scheduler;

/**
 * Writes events for LQDeck into the `outbox` table. Always called inside the
 * transaction that changes the state the event describes, so an event can never
 * exist only in memory (docs/v4-local-scheduler.md § 8.1).
 */
final class EventOutbox
{
	/**
	 * @param array<string, mixed> $payload
	 */
	public static function insert(SqliteConnection $connection, string $type, array $payload, string|null $runUuid = null, int|null $occurredAtMs = null): string
	{
		$eventId = Uuid::v7();
		$now = Clock::nowMs();

		$connection->execute(
			'INSERT INTO outbox (event_id, type, run_uuid, payload, encoding, occurred_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
			[$eventId, $type, $runUuid, self::encode($payload), 'json', $occurredAtMs ?? $now, $now],
		);

		return $eventId;
	}

	/**
	 * @param array<mixed>|null $value
	 */
	public static function encode(array|null $value): string
	{
		return LocalRunContext::encode($value);
	}
}
