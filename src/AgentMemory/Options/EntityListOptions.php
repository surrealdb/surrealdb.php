<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Entities;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Entities::list()}, {@see Entities::listAll()} and {@see Entities::count()}. */
final readonly class EntityListOptions
{
    /**
     * @param string|null $type   filter to one entity type (e.g. `person`)
     * @param int|null    $limit  entities per page (default 100, max 500)
     * @param string|null $cursor continuation token from a previous page's `nextCursor`
     * @param bool        $count  also report the filtered total as `totalSize` (costs a full count)
     */
    public function __construct(
        public ?string $type = null,
        public ?int $limit = null,
        public ?string $cursor = null,
        public bool $count = false,
    ) {}

    /**
     * @internal query parameters for `GET /entities`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'type' => $this->type,
        ]) + Payload::page($this->limit, $this->cursor, $this->count);
    }
}
