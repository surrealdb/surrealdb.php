<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Payload;
use SurrealDB\AgentMemory;

/** Options for {@see AgentMemory::inspect()}. */
final readonly class InspectOptions
{
    /**
     * @param string|null $asOf       historical query timestamp (known/valid time)
     * @param string|null $atInstant  system-time query instant (MVCC)
     * @param string|null $validFrom  valid-time lower bound
     * @param string|null $validUntil valid-time upper bound
     */
    public function __construct(
        public ?string $asOf = null,
        public ?string $atInstant = null,
        public ?string $validFrom = null,
        public ?string $validUntil = null,
    ) {}

    /**
     * @internal query parameters for `GET /inspect`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::temporal($this->asOf, $this->atInstant, $this->validFrom, $this->validUntil);
    }
}
