<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see AgentMemory::state()}. */
final readonly class StateOptions
{
    /**
     * @param int|null $limit rows per table (default 100, max 500); a cut table is flagged in `truncated`
     */
    public function __construct(
        public ?int $limit = null,
    ) {}

    /**
     * @internal query parameters for `GET /state`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact(['limit' => $this->limit]);
    }
}
