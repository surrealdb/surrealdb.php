<?php

namespace SurrealDB\SDK\Exceptions;

/** Thrown when a pending call is terminated because the connection was closed. */
final class CallTerminatedException extends SurrealException
{
    public function __construct()
    {
        parent::__construct('The call was terminated because the connection was closed.');
    }
}
