<?php

namespace SurrealDB\AgentMemory;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SurrealDB\AgentMemory;

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
     * @param string                  $endpoint   API endpoint origin, e.g. `https://memory.example.com`
     * @param string                  $context    Agent Memory context id (API path segment); the server accepts
     *                                            letters, digits, and underscores
     * @param string                  $apiKey     context API key (`sp-…`), sent as `Authorization: Bearer <key>`
     * @param int                     $maxRetries maximum retry attempts for idempotent requests
     * @param AgentMemoryRuntime|null $runtime    runtime profile ({@see AgentMemoryRuntime::sync()} when null);
     *                                            an explicit `$httpClient` wins over the runtime's client
     */
    public function __construct(
        public string $endpoint,
        public string $context,
        #[\SensitiveParameter] public string $apiKey,
        public int $maxRetries = self::DEFAULT_MAX_RETRIES,
        public ?AgentMemoryRuntime $runtime = null,
        public ?ClientInterface $httpClient = null,
        public ?RequestFactoryInterface $requestFactory = null,
        public ?StreamFactoryInterface $streamFactory = null,
    ) {
        Guard::notBlank($this->endpoint, 'Agent Memory endpoint is required.');
        Guard::notBlank($this->context, 'Agent Memory context is required.');
        Guard::notBlank($this->apiKey, 'Agent Memory API key is required.');
    }

    /**
     * The options as `var_dump()` and `print_r()` show them, with the API key redacted.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return array_replace(get_object_vars($this), ['apiKey' => '[redacted]']);
    }
}
