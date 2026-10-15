<?php

namespace SurrealDB\Auth;

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
        public array $variables = [],
    ) {}

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
