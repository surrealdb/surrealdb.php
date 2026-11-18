<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Entities;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Entities::search()}. */
final readonly class EntitySearchOptions
{
    /**
     * @param string|null $type  restrict matches to one entity type
     * @param int|null    $limit maximum matches (default 10)
     */
    public function __construct(
        public ?string $type = null,
        public ?int $limit = null,
    ) {}

    /**
     * @internal query parameters for `GET /entities/search`, beside `q`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'type' => $this->type,
            'limit' => $this->limit,
        ]);
    }
}
