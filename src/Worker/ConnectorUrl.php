<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Worker;

use Nette\Utils\Strings;

/**
 * Normalizes monitor connector base URL from NEON config (may omit scheme).
 */
final class ConnectorUrl
{
	public static function normalize(string $url): string
	{
		$url = Strings::trim($url);

		if ($url === '') {
			return $url;
		}

		if (!\preg_match('#^https?://#i', $url)) {
			return 'https://' . $url;
		}

		return $url;
	}
}
