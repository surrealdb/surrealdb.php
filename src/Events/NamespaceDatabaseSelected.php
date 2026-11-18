<?php

namespace SurrealDB\Events;

use SurrealDB\Protocol\NamespaceDatabase;

/** Dispatched when a session selects a namespace and/or database. */
final readonly class NamespaceDatabaseSelected
{
    public function __construct(
        public NamespaceDatabase $selected,
        public ?string $session = null,
    ) {}
}
