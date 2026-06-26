<?php

namespace SurrealDB\SDK\Exceptions;

/** Thrown when an operation requires an active connection but none is available. */
final class ConnectionUnavailableException extends SurrealException
{
    public function __construct()
    {
        parent::__construct(
            'You must be connected to a SurrealDB instance before performing this operation.',
        );
    }
}
