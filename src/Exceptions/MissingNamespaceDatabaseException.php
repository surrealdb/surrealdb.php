<?php

namespace SurrealDB\Exceptions;

/** Thrown when an operation requires a namespace and/or database to be selected. */
final class MissingNamespaceDatabaseException extends SurrealException
{
    public function __construct()
    {
        parent::__construct('There is no namespace and/or database selected.');
    }
}
