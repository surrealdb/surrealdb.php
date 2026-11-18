<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Facts;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Facts::attributes()} and {@see Facts::allAttributes()}. */
final readonly class AttributeListOptions
{
    /**
     * @param EntityRef|string|null $entity filter to one entity's attributes, as `<type>/<name>`
     * @param string|null           $key    filter by attribute key
     * @param int|null              $limit  rows per page (default 100, max 500)
     * @param string|null           $cursor continuation token from a previous page's `nextCursor`
     * @param bool                  $count  also report the filtered total as `totalSize` (costs a full count)
     */
    public function __construct(
        public EntityRef|string|null $entity = null,
        public ?string $key = null,
        public ?int $limit = null,
        public ?string $cursor = null,
        public bool $count = false,
    ) {}

    /**
     * @internal query parameters for `GET /attributes`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'entity' => Payload::ref($this->entity),
            'key' => $this->key,
        ]) + Payload::page($this->limit, $this->cursor, $this->count);
    }
}
