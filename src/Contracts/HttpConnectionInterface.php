<?php

namespace SurrealDB\Contracts;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use SurrealDB\Exceptions\HttpConnectionException;

/**
 * A single HTTP request/response exchange — the one connection primitive shared
 * by every HTTP client in the SDK (the SurrealDB database transport and the
 * Spectron client alike). Implementations perform exactly one round-trip and
 * raise on a non-success status, leaving payload encoding to the caller.
 */
interface HttpConnectionInterface
{
    /**
     * Send one request and return the PSR-7 response.
     *
     * @param array<string,string> $headers
     *
     * @throws HttpConnectionException   on a non-2xx response
     * @throws ClientExceptionInterface  when the underlying HTTP client fails to perform the exchange
     */
    public function request(string $url, string $method, ?string $body, array $headers): ResponseInterface;
}
