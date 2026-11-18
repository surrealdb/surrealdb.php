<?php

namespace SurrealDB\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SurrealDB\Contracts\HttpClientProviderInterface;
use SurrealDB\Exceptions\SurrealException;

/**
 * Resolves the PSR-18 client and PSR-17 factories to use for HTTP, preferring
 * explicitly-provided instances and falling back to php-http/discovery when
 * present. Depends only on the PSR interfaces, so it is shared by every HTTP
 * client in the SDK without dragging in any driver-specific context.
 */
final class PsrHttpClientProvider implements HttpClientProviderInterface
{
    public function __construct(
        private ?ClientInterface $client = null,
        private ?RequestFactoryInterface $requestFactory = null,
        private ?StreamFactoryInterface $streamFactory = null,
    ) {}

    public function client(): ClientInterface
    {
        return $this->client ??= $this->discoverClient();
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory ??= $this->discoverRequestFactory();
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory ??= $this->discoverStreamFactory();
    }

    private function discoverClient(): ClientInterface
    {
        if (class_exists(\Http\Discovery\Psr18ClientDiscovery::class)) {
            return \Http\Discovery\Psr18ClientDiscovery::find();
        }

        throw $this->missing('a PSR-18 HTTP client');
    }

    private function discoverRequestFactory(): RequestFactoryInterface
    {
        if (class_exists(\Http\Discovery\Psr17FactoryDiscovery::class)) {
            return \Http\Discovery\Psr17FactoryDiscovery::findRequestFactory();
        }

        throw $this->missing('a PSR-17 request factory');
    }

    private function discoverStreamFactory(): StreamFactoryInterface
    {
        if (class_exists(\Http\Discovery\Psr17FactoryDiscovery::class)) {
            return \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory();
        }

        throw $this->missing('a PSR-17 stream factory');
    }

    private function missing(string $what): SurrealException
    {
        return new SurrealException(
            "Could not resolve {$what}. Install a PSR-18/PSR-17 implementation " .
            '(e.g. `composer require guzzlehttp/guzzle php-http/discovery`) or pass one explicitly.',
        );
    }
}
