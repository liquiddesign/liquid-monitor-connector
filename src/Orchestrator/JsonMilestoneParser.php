<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Orchestrator;

use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Strings;

final class JsonMilestoneParser
{
	/**
	 * @return array<string, mixed>|null
	 */
	public function extract(string $text): ?array
	{
		if (!\preg_match_all('/```json\s*(.+?)\s*```/s', $text, $matches)) {
			return null;
		}

		$last = \end($matches[1]);

		try {
			/** @var array<string, mixed> $decoded */
			$decoded = Json::decode((string) $last, Json::FORCE_ARRAY);

			return $decoded;
		} catch (JsonException) {
			return null;
		}
	}

	public function collectTextFromOutput(string $stdout): string
	{
		$lines = \preg_split('/\r?\n/', Strings::trim($stdout)) ?: [];
		$collected = '';
		$matchedAny = false;

		foreach ($lines as $line) {
			$line = Strings::trim($line);

			if ($line === '') {
				continue;
			}

			try {
				/** @var array<string, mixed> $event */
				$event = Json::decode($line, Json::FORCE_ARRAY);
			} catch (JsonException) {
				continue;
			}

			$matchedAny = true;
			$collected .= $this->extractTextFromEvent($event);
		}

		return $matchedAny ? $collected : $stdout;
	}

	/**
	 * @param array<string, mixed> $event
	 */
	private function extractTextFromEvent(array $event): string
	{
		if (isset($event['message']['content']) && \is_array($event['message']['content'])) {
			$buf = '';

			foreach ($event['message']['content'] as $block) {
				if (\is_array($block) && ($block['type'] ?? null) === 'text' && \is_string($block['text'] ?? null)) {
					$buf .= $block['text'];
				}
			}

			if ($buf !== '') {
				return $buf;
			}
		}

		if (($event['type'] ?? null) === 'result' && \is_string($event['result'] ?? null)) {
			return $event['result'];
		}

		if (\is_string($event['text'] ?? null)) {
			return $event['text'];
		}

		if (\is_string($event['content'] ?? null)) {
			return $event['content'];
		}

		return '';
	}
}
