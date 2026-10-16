<?php

declare(strict_types=1);

// Minimal LQDeck for the end-to-end test: records every request, accepts every
// event (docs/v4-contract.md § 6), serves no commands.

$log = \getenv('LQCRON_E2E_REQUESTS');
$body = (string) \file_get_contents('php://input');
\file_put_contents($log, \json_encode([
	'method' => $_SERVER['REQUEST_METHOD'],
	'uri' => $_SERVER['REQUEST_URI'],
	'api_key' => $_SERVER['HTTP_X_API_KEY'] ?? null,
	'version' => $_SERVER['HTTP_X_CONNECTOR_VERSION'] ?? null,
	'body' => \json_decode($body, true),
]) . "\n", \FILE_APPEND | \LOCK_EX);

\header('Content-Type: application/json');

if (\str_contains($_SERVER['REQUEST_URI'], '/v4/commands')) {
	echo \json_encode(['commands' => []]);

	return true;
}

$payload = \json_decode($body, true);
$accepted = \array_map(static fn (array $event): string => $event['event_id'], $payload['events'] ?? []);
echo \json_encode(['accepted' => $accepted, 'rejected' => [], 'max_batch' => 200]);

return true;
