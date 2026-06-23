<?php

namespace SurrealDB\SDK\Exceptions;

/** Thrown when an HTTP request to the server fails with a non-success status. */
final class HttpConnectionException extends SurrealException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $statusText = '',
        public readonly string $body = '',
    ) {
        parent::__construct("HTTP connection failed ({$status}): {$message}");
    }
}
