<?php

namespace SurrealDB\Exceptions;

/** Thrown when an HTTP request to the server fails with a non-success status. */
final class HttpConnectionException extends SurrealException
{
    /**
     * @param array<string,list<string>> $headers response headers, PSR-7 shape
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $statusText = '',
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
        parent::__construct("HTTP connection failed ({$status}): {$message}");
    }
}
