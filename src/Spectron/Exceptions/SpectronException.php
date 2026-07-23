<?php

namespace SurrealDB\Spectron\Exceptions;

use SurrealDB\Exceptions\SurrealException;

/**
 * Base class for every error raised by the Spectron client, mirroring the
 * RFC 7807 problem-details shape the API responds with. Failed HTTP statuses
 * surface as the status-specific subclasses ({@see AuthException},
 * {@see ValidationException}, ...); encode/decode failures use this base class
 * directly with the offending status.
 */
class SpectronException extends SurrealException
{
    /**
     * @param string              $title      short error title from the API or a generic label
     * @param int                 $status     HTTP status code, or `0` for connection/client-side failures
     * @param string|null         $detail     human-readable detail when provided
     * @param string|null         $type       RFC 7807 `type` URI when provided
     * @param string|null         $instance   RFC 7807 `instance` when provided
     * @param array<string,mixed> $extensions additional problem-details fields
     */
    public function __construct(
        public readonly string $title,
        public readonly int $status = 0,
        public readonly ?string $detail = null,
        public readonly ?string $type = null,
        public readonly ?string $instance = null,
        public readonly array $extensions = [],
        ?\Throwable $previous = null,
    ) {
        $message = "[{$status}] {$title}" . ($detail !== null ? ": {$detail}" : '');

        parent::__construct($message, $status, $previous);
    }
}
