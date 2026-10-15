<?php

namespace SurrealDB\Exceptions;

/** Thrown when a referenced session does not exist on the connection. */
final class InvalidSessionException extends SurrealException
{
    public function __construct(public readonly ?string $session)
    {
        parent::__construct('The provided session is invalid: ' . ($session ?? 'null'));
    }
}
