<?php

namespace SurrealDB\Auth;

/** Namespace-scoped user credentials. */
final readonly class NamespaceAuth implements Credentials
{
    public function __construct(
        public string $namespace,
        public string $username,
        public string $password,
    ) {}

    public function toArray(): array
    {
        return [
            'ns' => $this->namespace,
            'user' => $this->username,
            'pass' => $this->password,
        ];
    }
}
