<?php

namespace SurrealDB\SDK\Auth;

/** Database-scoped user credentials. */
final readonly class DatabaseAuth implements Credentials
{
    public function __construct(
        public string $namespace,
        public string $database,
        public string $username,
        public string $password,
    ) {}

    public function toArray(): array
    {
        return [
            'ns' => $this->namespace,
            'db' => $this->database,
            'user' => $this->username,
            'pass' => $this->password,
        ];
    }
}
