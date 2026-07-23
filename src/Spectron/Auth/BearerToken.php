<?php

namespace SurrealDB\Spectron\Auth;

/** Authenticates with a bearer token: `Authorization: Bearer <token>`. */
final readonly class BearerToken implements AuthenticationInterface
{
    public function __construct(private string $token) {}

    public function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }
}
