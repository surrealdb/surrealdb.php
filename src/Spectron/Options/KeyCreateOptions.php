<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\Keys;
use SurrealDB\Spectron\Payload;

/** Options for {@see Keys::create()}. */
final readonly class KeyCreateOptions
{
    /**
     * @param string|null              $name       human-readable key name
     * @param array<string,mixed>|null $grants     grants the key carries; inherits the caller's when omitted
     * @param int|null                 $ttlSeconds validity window in seconds
     */
    public function __construct(
        public ?string $name = null,
        public ?array $grants = null,
        public ?int $ttlSeconds = null,
    ) {}

    /**
     * @internal wire body for `POST /keys`; empty when no body field is set
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'name' => $this->name,
            'grants' => $this->grants,
        ]);
    }

    /**
     * @internal query parameters for `POST /keys`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact(['ttlSeconds' => $this->ttlSeconds]);
    }
}
