<?php

namespace SurrealDB\Spectron;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SurrealDB\Spectron;
use SurrealDB\Spectron\Auth\AuthenticationInterface;
use SurrealDB\Spectron\Auth\BearerToken;
use function is_string;

/**
 * Options for constructing a {@see Spectron} client.
 *
 * Request timeouts are not configured here: PSR-18 clients own their timeout
 * configuration, so set it on the client you pass (or the one discovery finds).
 *
 * Pass a {@see SpectronRuntime} to run the client on an async runtime
 * (OpenSwoole coroutines, Amp/Revolt fibers as under FrankenPHP worker mode);
 * the default is the blocking synchronous profile.
 */
final readonly class SpectronOptions
{
    /** Maximum retry attempts for idempotent requests. */
    public const int DEFAULT_MAX_RETRIES = 3;

    /**
     * @param string                         $endpoint   API endpoint origin without trailing slash
     * @param string                         $context    Spectron context id (API path segment)
     * @param string|AuthenticationInterface $apiKey     API key sent as an `Authorization: Bearer` token,
     *                                                   or an {@see AuthenticationInterface} for custom schemes
     * @param int                            $maxRetries maximum retry attempts for idempotent requests
     * @param SpectronRuntime|null           $runtime    runtime profile ({@see SpectronRuntime::sync()} when null);
     *                                                   an explicit `$httpClient` wins over the runtime's client
     */
    public function __construct(
        public string $endpoint,
        public string $context,
        public string|AuthenticationInterface $apiKey,
        public int $maxRetries = self::DEFAULT_MAX_RETRIES,
        public ?SpectronRuntime $runtime = null,
        public ?ClientInterface $httpClient = null,
        public ?RequestFactoryInterface $requestFactory = null,
        public ?StreamFactoryInterface $streamFactory = null,
    ) {
        if ($this->endpoint === '') {
            throw new \InvalidArgumentException('Spectron endpoint is required.');
        }

        if ($this->context === '') {
            throw new \InvalidArgumentException('Spectron context is required.');
        }

        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('Spectron API key is required.');
        }
    }

    /** The authentication strategy: a bare API key becomes a bearer token. */
    public function authentication(): AuthenticationInterface
    {
        return is_string($this->apiKey) ? new BearerToken($this->apiKey) : $this->apiKey;
    }
}
