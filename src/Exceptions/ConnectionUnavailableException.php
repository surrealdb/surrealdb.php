<?php

namespace SurrealDB\Exceptions;

/** Thrown when an operation requires an active connection but none is available. */
final class ConnectionUnavailableException extends SurrealException
{
    public function __construct(
        string $message = 'You must be connected to a SurrealDB instance before performing this operation.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
