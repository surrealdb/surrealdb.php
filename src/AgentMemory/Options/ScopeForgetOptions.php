<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Scopes;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Scopes::forget()}. */
final readonly class ScopeForgetOptions
{
    /**
     * @param string|null $path scope subtree to forget; the server requires one (400 `path is required`
     *                          without it), so omitting it never forgets the whole context
     */
    public function __construct(
        public ?string $path = null,
    ) {
        if ($this->path !== null) {
            Guard::notBlank($this->path, 'ScopeForgetOptions requires a non-empty path when one is given.');
        }
    }

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
