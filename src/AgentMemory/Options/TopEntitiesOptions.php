<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Entities;
use SurrealDB\AgentMemory\Enum\EntityRanking;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Entities::top()}. */
final readonly class TopEntitiesOptions
{
    /**
     * @param EntityRanking|string|null $by    ranking; defaults to `coverage`
     * @param string|null               $type  restrict to one entity type
     * @param int|null                  $limit maximum entities (default 10)
     */
    public function __construct(
        public EntityRanking|string|null $by = null,
        public ?string $type = null,
        public ?int $limit = null,
    ) {}

    /**
     * @internal query parameters for `GET /entities/top`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'by' => Payload::enum($this->by),
            'type' => $this->type,
            'limit' => $this->limit,
        ]);
    }
}
