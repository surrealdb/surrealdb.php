<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\Traces;
use SurrealDB\Spectron\Payload;

/** Options for {@see Traces::list()}. */
final readonly class TraceListOptions
{
    /**
     * @param int|null $limit maximum number of trace records to return
     */
    public function __construct(
        public ?int $limit = null,
    ) {}

    /**
     * @internal query parameters for `GET /traces`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact(['limit' => $this->limit]);
    }
}
