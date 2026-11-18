<?php

namespace SurrealDB\Events;

use SurrealDB\Auth\Tokens;

/** Dispatched when a session's authentication state changes (or is cleared). */
final readonly class AuthChanged
{
    public function __construct(
        public ?Tokens $tokens,
        public ?string $session = null,
    ) {}
}
