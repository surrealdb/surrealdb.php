<?php

namespace SurrealDB\SDK\Auth;

use SurrealDB\SDK\Support\Redacted;

/** Database-scoped user credentials. */
final readonly class DatabaseAuth implements Credentials
{
    public function __construct(
        public string $namespace,
        public string $database,
        public string $username,
        #[\SensitiveParameter]
        public string $password,
    ) {}

    /**
     * @return array<string, ?string>
     */
    public function __debugInfo(): array
    {
        return [
            'namespace' => $this->namespace,
            'database' => $this->database,
            'username' => $this->username,
            'password' => Redacted::value($this->password),
        ];
    }

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
