<?php

namespace SurrealDB\AgentMemory\Exceptions;

use SurrealDB\Exceptions\SurrealException;
use SurrealDB\Exceptions\SurrealExceptionInterface;

/**
 * Base class for every error the Agent Memory client raises for a request.
 * Failed HTTP statuses surface as the status-specific subclasses
 * ({@see AuthException}, {@see ValidationException}, ...); encode/decode
 * failures use this base class directly with the offending status. Invalid
 * input is refused before any request with {@see InvalidArgumentException},
 * which extends `\InvalidArgumentException` instead; both implement
 * {@see SurrealExceptionInterface}.
 *
 * The API reports errors as `{"message": "…"}`, which becomes the `title`;
 * RFC 7807 problem-details members (`title`, `detail`, `type`, `instance`) are
 * read too when a gateway in front of the API answers in that shape.
 */
class AgentMemoryException extends SurrealException
{
    /**
     * @param string              $title      short error title from the API or a generic label
     * @param int                 $status     HTTP status code, or `0` for connection/client-side failures
     * @param string|null         $detail     human-readable detail when provided
     * @param string|null         $type       RFC 7807 `type` URI when provided
     * @param string|null         $instance   RFC 7807 `instance` when provided
     * @param array<string,mixed> $extensions additional error-body fields
     * @param int|null            $retryAfter seconds the server asked the caller to wait (`Retry-After`),
     *                                        sent with `429` throttling and `503` load shedding
     */
    public function __construct(
        public readonly string $title,
        public readonly int $status = 0,
        public readonly ?string $detail = null,
        public readonly ?string $type = null,
        public readonly ?string $instance = null,
        public readonly array $extensions = [],
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        $message = "[{$status}] {$title}" . ($detail !== null ? ": {$detail}" : '');

        parent::__construct($message, $status, $previous);
    }
}
