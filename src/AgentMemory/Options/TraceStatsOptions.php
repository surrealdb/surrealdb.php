<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Traces;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Traces::stats()}. */
final readonly class TraceStatsOptions
{
    /**
     * @param int|null $windowHours trailing window the statistics cover, in hours (server default 24)
     */
    public function __construct(
        public ?int $windowHours = null,
    ) {}

    /**
     * @internal query parameters for `GET /traces/stats`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact(['windowHours' => $this->windowHours]);
    }
}
