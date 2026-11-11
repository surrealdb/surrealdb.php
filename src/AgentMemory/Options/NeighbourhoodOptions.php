<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Entities;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Entities::neighbours()} and {@see Entities::allNeighbours()}. */
final readonly class NeighbourhoodOptions
{
    /**
     * @param int|null    $minFacts drop neighbours with fewer facts than this
     * @param int|null    $limit    neighbours per page
     * @param string|null $cursor   continuation token from a previous page's `nextCursor`
     * @param bool        $count    also report the subject's visible edge total as `totalSize`;
     *                              cannot be combined with a positive `minFacts`
     */
    public function __construct(
        public ?int $minFacts = null,
        public ?int $limit = null,
        public ?string $cursor = null,
        public bool $count = false,
    ) {
        if ($this->count && $this->minFacts !== null && $this->minFacts > 0) {
            throw new InvalidArgumentException('NeighbourhoodOptions cannot combine count with a positive minFacts.');
        }
    }

    /**
     * @internal query parameters for `GET /entities/{type}/{name}/neighbourhood`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'minFacts' => $this->minFacts,
        ]) + Payload::page($this->limit, $this->cursor, $this->count);
    }
}
