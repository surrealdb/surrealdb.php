<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Scopes;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Scopes::list()}. */
final readonly class ScopeListOptions
{
    /**
     * @param int|null    $limit  scope nodes to scan per page (default 100, max 500)
     * @param string|null $cursor continuation token from a previous page's `nextCursor`
     */
    public function __construct(
        public ?int $limit = null,
        public ?string $cursor = null,
    ) {}

    /**
     * @internal query parameters for `GET /scopes`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::page($this->limit, $this->cursor);
    }
}
