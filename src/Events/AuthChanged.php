<?php

namespace SurrealDB\SDK\Events;

use SurrealDB\SDK\Auth\Tokens;

/** Dispatched when a session's authentication state changes (or is cleared). */
final readonly class AuthChanged
{
    public function __construct(
        public ?Tokens $tokens,
        public ?string $session = null,
    ) {}
}
