<?php

namespace SurrealDB\Http;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Psr7\PsrAdapter;
use Amp\Http\Client\Psr7\PsrHttpClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use SurrealDB\Exceptions\SurrealException;

/**
 * Builds a PSR-18 client backed by amphp/http-client, for the Amp / Revolt
 * runtime (e.g. FrankenPHP worker mode) where a blocking PSR-18 client would
 * stall the event loop. Every consumer of the SDK's PSR-18 seam can take this
 * client unchanged: requests suspend the current fiber instead of blocking,
 * and response bodies are lazy streams, so incremental readers (e.g. the
 * Spectron SSE chat stream) yield to the loop between reads.
 *
 * Requires amphp/http-client-psr7 and discoverable PSR-17 factories.
 */
final class AmpPsr18Client
{
    /**
     * @param HttpClient|null $httpClient override the underlying Amp client
     *                                    (defaults to `HttpClientBuilder::buildDefault()`)
     */
    public static function create(?HttpClient $httpClient = null): ClientInterface
    {
        if (!class_exists(PsrHttpClient::class)) {
            throw new SurrealException(
                'The "amphp/http-client-psr7" package is required for non-blocking HTTP on the Amp runtime. '
                . 'Install it with "composer require amphp/http-client-psr7".',
            );
        }

        if (!class_exists(Psr17FactoryDiscovery::class)) {
            throw new SurrealException(
                'Could not resolve PSR-17 factories for the Amp PSR-18 client. Install a PSR-17 implementation '
                . 'and php-http/discovery (e.g. `composer require guzzlehttp/guzzle php-http/discovery`).',
            );
        }

        return new PsrHttpClient(
            $httpClient ?? HttpClientBuilder::buildDefault(),
            new PsrAdapter(
                Psr17FactoryDiscovery::findRequestFactory(),
                Psr17FactoryDiscovery::findResponseFactory(),
            ),
        );
    }
}
