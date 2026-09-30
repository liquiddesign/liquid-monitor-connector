<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry;

/**
 * Stav opcache aktuálního procesu — podle něj se pozná studený request.
 *
 * Pod FastCGI PHP po každém requestu zahodí userland stav (i statické vlastnosti), takže pořadí
 * requestu v procesu z PHP spočítat nejde. Rozdíl `misses` mezi začátkem a koncem requestu ale říká,
 * kolik skriptů request kompiloval, a `start_time` je start opcache procesu (= stáří procesu,
 * když má každý proces vlastní opcache — mod_fcgid s `PHP_FCGI_CHILDREN 0`).
 */
final class OpcacheProbe
{
	/**
	 * @return array{int, float}|null [misses, start_time]
	 */
	public static function read(): ?array
	{
		if (!\function_exists('opcache_get_status')) {
			return null;
		}

		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- s opcache.restrict_api vyhodí warning; telemetrie ho nesmí propsat do requestu
		$status = @\opcache_get_status(false);

		if (!\is_array($status) || !isset($status['opcache_statistics']) || !\is_array($status['opcache_statistics'])) {
			return null;
		}

		$statistics = $status['opcache_statistics'];

		return [(int) ($statistics['misses'] ?? 0), (float) ($statistics['start_time'] ?? 0)];
	}
}
