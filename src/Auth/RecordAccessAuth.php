<?php

namespace SurrealDB\SDK\Auth;

use SurrealDB\SDK\Support\Redacted;

/** Record access (scope) credentials with arbitrary signin variables. */
final readonly class RecordAccessAuth implements Credentials
{
    /**
     * @param array<string,mixed> $variables
     */
    public function __construct(
        public string $namespace,
        public string $database,
        public string $access,
        #[\SensitiveParameter]
        public array $variables = [],
    ) {}

    /**
     * The variables are the access method's own, a password among them as
     * often as not: only their names show.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'namespace' => $this->namespace,
            'database' => $this->database,
            'access' => $this->access,
            'variables' => Redacted::values($this->variables),
        ];
    }

    public function toArray(): array
    {
        return [
            'ns' => $this->namespace,
            'db' => $this->database,
            'ac' => $this->access,
            ...$this->variables,
        ];
    }
}
