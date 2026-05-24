<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Triage;

use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Strings;

/**
 * Parses agent stdout (Claude stream-json, Cursor stream-json) and extracts the final
 * triage payload from the last ```json fenced block in the agent's last text message.
 *
 * Stream-json formats differ across agents but both ultimately emit assistant text
 * containing a ```json … ``` block holding the triage result.
 */
final class ResultExtractor
{
	/**
	 * @return array<string, mixed>|null
	 */
	public function extract(string $stdout): ?array
	{
		$text = $this->collectText($stdout);

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

	/**
	 * Collect assistant text from stream-json output. If parsing as stream-json fails
	 * (e.g. agent emitted plain text), return the raw stdout unchanged.
	 */
	private function collectText(string $stdout): string
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
		// Claude stream-json: events shaped like { type:"assistant", message:{ content:[ {type:"text", text:"…"} ] } }
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

		// Cursor stream-json terminal event: { type:"result", subtype:"success", result:"…", … }
		if (($event['type'] ?? null) === 'result' && \is_string($event['result'] ?? null)) {
			return $event['result'];
		}

		// Other stream-json shapes (Cursor intermediate, custom): { text:"…" } or { content:"…" }
		if (\is_string($event['text'] ?? null)) {
			return $event['text'];
		}

		if (\is_string($event['content'] ?? null)) {
			return $event['content'];
		}

		return '';
	}
}
