<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Entities;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Entities::get()}. */
final readonly class EntityGetOptions
{
    /**
     * @param int|null    $limit      rows per fact section (default and max 500); a cut section is flagged in `truncated`
     * @param string|null $asOf       known time: read the facts as they stood at this instant
     * @param string|null $atInstant  system time: read through MVCC at this instant
     * @param string|null $validFrom  world-time lower bound
     * @param string|null $validUntil world-time upper bound
     */
    public function __construct(
        public ?int $limit = null,
        public ?string $asOf = null,
        public ?string $atInstant = null,
        public ?string $validFrom = null,
        public ?string $validUntil = null,
    ) {}

    /**
     * @internal query parameters for `GET /entities/{type}/{name}`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact(['limit' => $this->limit])
            + Payload::temporal($this->asOf, $this->atInstant, $this->validFrom, $this->validUntil);
    }
}
