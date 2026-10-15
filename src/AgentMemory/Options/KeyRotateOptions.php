<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Keys;
use SurrealDB\AgentMemory\Payload;

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
