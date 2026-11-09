<?php

namespace SurrealDB\SDK\Auth;

use SurrealDB\SDK\Support\Redacted;

/** Namespace-scoped user credentials. */
final readonly class NamespaceAuth implements Credentials
{
    public function __construct(
        public string $namespace,
        public string $username,
        #[\SensitiveParameter]
        public string $password,
    ) {}

    /**
     * @return array<string, ?string>
     */
    public function __debugInfo(): array
    {
        return ['namespace' => $this->namespace, 'username' => $this->username, 'password' => Redacted::value($this->password)];
    }

    public function toArray(): array
    {
        return [
            'ns' => $this->namespace,
            'user' => $this->username,
            'pass' => $this->password,
        ];
    }
}
