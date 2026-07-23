<?php

namespace SurrealDB\Spectron\Auth;

/**
 * Authenticates with an API key sent in a dedicated header (default `X-API-Key`;
 * override the header name to match your deployment).
 */
final readonly class ApiKey implements AuthenticationInterface
{
    public function __construct(
        private string $key,
        private string $header = 'X-API-Key',
    ) {}

    public function headers(): array
    {
        return [$this->header => $this->key];
    }
}
