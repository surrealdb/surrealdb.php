<?php

namespace SurrealDB\SDK\Auth;

/** Root (system) user credentials. */
final readonly class RootAuth implements Credentials
{
    public function __construct(
        public string $username,
        public string $password,
    ) {}

    public function toArray(): array
    {
        return ['user' => $this->username, 'pass' => $this->password];
    }
}
