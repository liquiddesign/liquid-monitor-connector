<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry\Agent;

use Nette\Utils\FileSystem;

// phpcs:disable Generic.PHP.NoSilencedErrors.Discouraged -- plný nebo nedostupný disk nesmí agenta shodit, zápis se jen vynechá

/**
 * Minutové záznamy do denních JSONL souborů. Denní strop chrání disk — telemetrie nesmí zaplnit
 * disk aplikace (nad stropem se zapisovat přestane a do souboru `.full` se poznamená kdy).
 */
final class JsonlFileSink implements Sink
{
	public function __construct(private string $directory, private int $maxBytesPerDay = 200 * 1024 * 1024)
	{
	}

	public function write(array $minutes): void
	{
		if ($minutes === []) {
			return;
		}

		try {
			FileSystem::createDir($this->directory);
		} catch (\Throwable) {
			return;
		}

		$file = $this->directory . '/telemetry-' . \gmdate('Y-m-d') . '.jsonl';

		\clearstatcache(true, $file);

		if (\is_file($file) && (int) \filesize($file) >= $this->maxBytesPerDay) {
			@\file_put_contents($file . '.full', \gmdate('c') . "\n", \FILE_APPEND | \LOCK_EX);

			return;
		}

		$lines = '';

		foreach ($minutes as $minute) {
			$line = \json_encode($minute, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);

			if ($line === false) {
				continue;
			}

			$lines .= $line . "\n";
		}

		@\file_put_contents($file, $lines, \FILE_APPEND | \LOCK_EX);
	}
}
