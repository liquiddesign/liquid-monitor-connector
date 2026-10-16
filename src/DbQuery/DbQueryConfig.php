<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\DbQuery;

/**
 * DI-injected settings for the DB query API presenter.
 *
 * Vlastnosti jsou kvůli PHP 8.0 bez `readonly` — po sestavení je nepřepisovat.
 * @immutable
 */
final class DbQueryConfig
{
	public function __construct(public ?string $apiToken = null)
	{
	}
}
