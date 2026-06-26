<?php

namespace SurrealDB\SDK\Auth;

/** Bearer access credentials (a pre-issued access key). */
final readonly class BearerAuth implements Credentials
{
    public function __construct(
        public string $namespace,
        public string $database,
        public string $access,
        public string $key,
    ) {}

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
