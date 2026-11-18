<?php

namespace SurrealDB\Protocol;

/** A namespace and database selection pair. */
final readonly class NamespaceDatabase
{
    public function __construct(
        public ?string $namespace = null,
        public ?string $database = null,
    ) {}
}
