<?php

namespace SurrealDB\SDK\Events;

/** Dispatched when the connection is established and ready. */
final readonly class Connected
{
    public function __construct(public string $version) {}
}
