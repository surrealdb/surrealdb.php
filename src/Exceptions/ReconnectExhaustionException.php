<?php

namespace SurrealDB\SDK\Exceptions;

/** Thrown when reconnect attempts have been exhausted. */
final class ReconnectExhaustionException extends SurrealException
{
    public function __construct()
    {
        parent::__construct('The reconnect attempts have been exhausted.');
    }
}
