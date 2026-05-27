<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

/**
 * Single source of truth for the connector version advertised to the
 * Liquid Monitor backend. Bump CURRENT whenever a release introduces a change
 * that affects the request/response contract with the backend.
 */
final class Version
{
	public const CURRENT = '2.0.0';

	public const HEADER_NAME = 'X-Connector-Version';

	public const STATUS_HEADER_NAME = 'X-Connector-Version-Status';

	public const STATUS_UNSUPPORTED = 'unsupported';

	private function __construct()
	{
	}
}
