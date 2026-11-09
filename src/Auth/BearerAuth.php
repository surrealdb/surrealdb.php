<?php

namespace SurrealDB\SDK\Auth;

use SurrealDB\SDK\Support\Redacted;

/** Bearer access credentials (a pre-issued access key). */
final readonly class BearerAuth implements Credentials
{
    public function __construct(
        public string $namespace,
        public string $database,
        public string $access,
        #[\SensitiveParameter]
        public string $key,
    ) {}

    /**
     * @return array<string, ?string>
     */
    public function __debugInfo(): array
    {
        return [
            'namespace' => $this->namespace,
            'database' => $this->database,
            'access' => $this->access,
            'key' => Redacted::value($this->key),
        ];
    }

    public function toArray(): array
    {
        return [
            'ns' => $this->namespace,
            'db' => $this->database,
            'ac' => $this->access,
            'key' => $this->key,
        ];
    }
}
