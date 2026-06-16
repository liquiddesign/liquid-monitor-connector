<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\DbQuery;

/**
 * DI-injected settings for the DB query API presenter.
 */
final class DbQueryConfig
{
	public function __construct(public readonly ?string $apiToken = null)
	{
	}
}
