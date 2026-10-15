<?php

namespace SurrealDB\Events;

/** Dispatched when the connection is established and ready. */
final readonly class Connected
{
    public function __construct(public string $version) {}
}
