<?php

namespace SurrealDB\Events;

/** Dispatched when a connection-level error occurs. */
final readonly class ConnectionError
{
    public function __construct(public \Throwable $error) {}
}
