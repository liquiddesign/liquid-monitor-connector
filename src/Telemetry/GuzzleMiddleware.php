<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware pro odchozí HTTP volání. Klienta si staví každý projekt sám, proto se
 * přidává ručně:
 *
 *     $stack->push(GuzzleMiddleware::create(), 'telemetry');
 *
 * Klíčem je host (ne celé URL — kardinalita), chybou status 5xx nebo výjimka.
 */
final class GuzzleMiddleware
{
	public const TYPE = 'http';

	public static function create(string $type = self::TYPE): \Closure
	{
		return static fn (callable $handler): \Closure => static function (RequestInterface $request, array $options) use ($handler, $type): PromiseInterface {
			$start = Recorder::begin();
			/** @var \GuzzleHttp\Promise\PromiseInterface $promise */
			$promise = $handler($request, $options);

			if ($start === 0) {
				return $promise;
			}

			$key = $request->getUri()->getHost();

			return $promise->then(
				static function (ResponseInterface $response) use ($type, $key, $start): ResponseInterface {
					Recorder::end($type, $key, $start, $response->getStatusCode() >= 500);

					return $response;
				},
				static function ($reason) use ($type, $key, $start): PromiseInterface {
					Recorder::end($type, $key, $start, true);

					return Create::rejectionFor($reason);
				},
			);
		};
	}
}
