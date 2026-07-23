<?php

namespace SurrealDB\Spectron\Exceptions;

/** Rate or token budget exceeded (429). */
final class RateLimitException extends SpectronException
{
    /**
     * @param int|null            $retryAfter seconds from `Retry-After` when numeric
     * @param array<string,mixed> $extensions
     */
    public function __construct(
        string $title,
        int $status = 429,
        ?string $detail = null,
        ?string $type = null,
        ?string $instance = null,
        array $extensions = [],
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($title, $status, $detail, $type, $instance, $extensions, $previous);
    }
}
