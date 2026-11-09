<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\DbQuery;

use Nette\Utils\Strings;

/**
 * DI-injected settings for the DB query API presenter.
 *
 * Vlastnosti jsou kvůli PHP 8.0 bez `readonly` — po sestavení je nepřepisovat.
 * @immutable
 */
final class DbQueryConfig
{
	/**
	 * Hosts the monitor may point the connection at. The monitor sends the database
	 * credentials, but the host app opens the connection — without a list, a caller from
	 * a trusted IP could make the production server connect anywhere in its network.
	 * The monitor's proxy mode always sends 127.0.0.1, so loopback is the default.
	 */
	public const DEFAULT_ALLOWED_HOSTS = ['127.0.0.1', 'localhost', '::1'];

	/**
	 * @param list<string> $allowedHosts
	 */
	public function __construct(public ?string $apiToken = null, public array $allowedHosts = self::DEFAULT_ALLOWED_HOSTS)
	{
	}

	public function isHostAllowed(string $host): bool
	{
		$host = Strings::lower(Strings::trim(Strings::trim($host), '[]'));

		foreach ($this->allowedHosts as $allowed) {
			if (Strings::lower(Strings::trim(Strings::trim($allowed), '[]')) === $host) {
				return true;
			}
		}

		return false;
	}
}
