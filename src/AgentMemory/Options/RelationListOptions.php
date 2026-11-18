<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Facts;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Facts::relations()} and {@see Facts::allRelations()}. */
final readonly class RelationListOptions
{
    /**
     * @param EntityRef|string|null $src    filter by subject entity, as `<type>/<name>`
     * @param EntityRef|string|null $dst    filter by object entity, as `<type>/<name>`
     * @param string|null           $label  filter by relation label
     * @param int|null              $limit  rows per page (default 100, max 500)
     * @param string|null           $cursor continuation token from a previous page's `nextCursor`
     * @param bool                  $count  also report the filtered total as `totalSize` (costs a full count)
     */
    public function __construct(
        public EntityRef|string|null $src = null,
        public EntityRef|string|null $dst = null,
        public ?string $label = null,
        public ?int $limit = null,
        public ?string $cursor = null,
        public bool $count = false,
    ) {}

    /**
     * @internal query parameters for `GET /relations`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'src' => Payload::ref($this->src),
            'dst' => Payload::ref($this->dst),
            'label' => $this->label,
        ]) + Payload::page($this->limit, $this->cursor, $this->count);
    }
}
