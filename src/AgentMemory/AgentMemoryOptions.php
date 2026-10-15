<?php

namespace SurrealDB\AgentMemory;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Auth\AuthenticationInterface;
use SurrealDB\AgentMemory\Auth\BearerToken;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use function is_string;

/**
 * Options for constructing a {@see AgentMemory} client.
 *
 * Request timeouts are not configured here: PSR-18 clients own their timeout
 * configuration, so set it on the client you pass (or the one discovery finds).
 *
 * Pass a {@see AgentMemoryRuntime} to run the client on an async runtime
 * (OpenSwoole coroutines, Amp/Revolt fibers as under FrankenPHP worker mode);
 * the default is the blocking synchronous profile.
 */
final readonly class AgentMemoryOptions
{
    /** Maximum retry attempts for idempotent requests. */
    public const int DEFAULT_MAX_RETRIES = 3;

    /**
     * @param string                         $endpoint   API endpoint origin, e.g. `https://memory.example.com`
     * @param string                         $context    Agent Memory context id (API path segment); the server accepts
     *                                                   letters, digits, and underscores
     * @param string|AuthenticationInterface $apiKey     context API key (`sp-…`) sent as an `Authorization: Bearer` token,
     *                                                   or an {@see AuthenticationInterface} for custom schemes
     * @param int                            $maxRetries maximum retry attempts for idempotent requests
     * @param AgentMemoryRuntime|null        $runtime    runtime profile ({@see AgentMemoryRuntime::sync()} when null);
     *                                                   an explicit `$httpClient` wins over the runtime's client
     */
    public function __construct(
        public string $endpoint,
        public string $context,
        public string|AuthenticationInterface $apiKey,
        public int $maxRetries = self::DEFAULT_MAX_RETRIES,
        public ?AgentMemoryRuntime $runtime = null,
        public ?ClientInterface $httpClient = null,
        public ?RequestFactoryInterface $requestFactory = null,
        public ?StreamFactoryInterface $streamFactory = null,
    ) {
        if (trim($this->endpoint) === '') {
            throw new InvalidArgumentException('Agent Memory endpoint is required.');
        }

        if (trim($this->context) === '') {
            throw new InvalidArgumentException('Agent Memory context is required.');
        }

        if (is_string($this->apiKey) && trim($this->apiKey) === '') {
            throw new InvalidArgumentException('Agent Memory API key is required.');
        }
    }

    /** The authentication strategy: a bare API key becomes a bearer token. */
    public function authentication(): AuthenticationInterface
    {
        return is_string($this->apiKey) ? new BearerToken($this->apiKey) : $this->apiKey;
    }
}
