<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\Keys;
use SurrealDB\Spectron\Payload;

/** Options for {@see Keys::rotate()}. */
final readonly class KeyRotateOptions
{
    /**
     * @param int|null $ttlSeconds validity window of the fresh secret in seconds
     */
    public function __construct(
        public ?int $ttlSeconds = null,
    ) {}

    /**
     * @internal query parameters for `POST /keys/{name}/rotate`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact(['ttlSeconds' => $this->ttlSeconds]);
    }
}
