<?php

namespace SurrealDB\SDK\Auth;

use SurrealDB\SDK\Support\Redacted;

/** Root (system) user credentials. */
final readonly class RootAuth implements Credentials
{
    public function __construct(
        public string $username,
        #[\SensitiveParameter]
        public string $password,
    ) {}

    /**
     * @return array<string, ?string>
     */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => Redacted::value($this->password)];
    }

    public function toArray(): array
    {
        return ['user' => $this->username, 'pass' => $this->password];
    }
}
