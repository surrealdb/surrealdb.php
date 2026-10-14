<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Entities;
use SurrealDB\AgentMemory\Payload;

/**
 * Options for {@see Entities::history()}: the tri-temporal window the
 * supersession history is read through.
 */
final readonly class EntityHistoryOptions
{
    /**
     * @param string|null $asOf       known time: read the history as it stood at this instant
     * @param string|null $atInstant  system time: read through MVCC at this instant
     * @param string|null $validFrom  world-time lower bound
     * @param string|null $validUntil world-time upper bound
     */
    public function __construct(
        public ?string $asOf = null,
        public ?string $atInstant = null,
        public ?string $validFrom = null,
        public ?string $validUntil = null,
    ) {}

    /**
     * @internal query parameters for `GET /entities/{type}/{name}/history/{key}`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::temporal($this->asOf, $this->atInstant, $this->validFrom, $this->validUntil);
    }
}
