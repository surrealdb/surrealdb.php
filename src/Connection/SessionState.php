<?php

namespace SurrealDB\Connection;

use SurrealDB\Auth\AuthState;

/**
 * Mutable state for a single session: its selected namespace/database, defined
 * variables, and authentication. The root session uses a null id.
 */
final class SessionState
{
    /** @var array<string,mixed> */
    public array $variables = [];

    public function __construct(
        public ?string $id = null,
        public ?string $namespace = null,
        public ?string $database = null,
        public AuthState $auth = new AuthState(),
    ) {}
}
