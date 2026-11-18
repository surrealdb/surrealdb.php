<?php

namespace SurrealDB\Exceptions;

/** Thrown when no engine is registered for the requested URL scheme. */
final class UnsupportedEngineException extends SurrealException
{
    public function __construct(public readonly string $scheme)
    {
        parent::__construct("The engine \"{$scheme}\" is not supported or configured.");
    }
}
