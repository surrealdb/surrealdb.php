<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Uncertainty;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Uncertainty::list()}, {@see Uncertainty::listAll()} and {@see Uncertainty::count()}. */
final readonly class UncertaintyListOptions
{
    /**
     * @param EntityRef|string|null $entity   restrict to one subject, as `<type>/<name>`
     * @param bool|null             $resolved `true` for settled flags only, `false` for open ones only
     * @param int|null              $limit    rows per page
     * @param string|null           $cursor   continuation token from a previous page's `nextCursor`
     * @param bool                  $count    also report the filtered total as `totalSize`
     */
    public function __construct(
        public EntityRef|string|null $entity = null,
        public ?bool $resolved = null,
        public ?int $limit = null,
        public ?string $cursor = null,
        public bool $count = false,
    ) {}

    /**
     * @internal query parameters for `GET /uncertainty`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'entity' => Payload::ref($this->entity),
            'resolved' => $this->resolved,
        ]) + Payload::page($this->limit, $this->cursor, $this->count);
    }
}
