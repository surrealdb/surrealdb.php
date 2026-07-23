<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\Scopes;
use SurrealDB\Spectron\Payload;

/** Options for {@see Scopes::forget()}. */
final readonly class ScopeForgetOptions
{
    /**
     * @param string|null $path scope subtree to forget; the whole context when omitted
     */
    public function __construct(
        public ?string $path = null,
    ) {}

    /**
     * @internal wire fields for `POST /scopes/forget`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact(['path' => $this->path]);
    }
}
