<?php

namespace SurrealDB\SDK\Exceptions;

/** Thrown when authentication or token renewal does not succeed. */
final class AuthenticationException extends SurrealException
{
    public function __construct(string $message = 'Authentication did not succeed.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
