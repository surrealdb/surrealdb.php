<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Enum\TraceKind;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see AgentMemory::audit()} and {@see AgentMemory::auditAll()}. */
final readonly class AuditOptions
{
    /**
     * @param string|null           $principal filter to one principal (API key id)
     * @param string|null           $key       alias for `principal`
     * @param TraceKind|string|null $kind      filter to one trace kind
     * @param string|null           $since     inclusive lower bound on the row's creation time (RFC 3339)
     * @param string|null           $until     inclusive upper bound on the row's creation time (RFC 3339)
     * @param int|null              $limit     rows per page (default 100, max 500)
     * @param string|null           $cursor    continuation token from a previous page's `nextCursor`
     */
    public function __construct(
        public ?string $principal = null,
        public ?string $key = null,
        public TraceKind|string|null $kind = null,
        public ?string $since = null,
        public ?string $until = null,
        public ?int $limit = null,
        public ?string $cursor = null,
    ) {}

    /**
     * @internal query parameters for `GET /audit`
     *
     * @return array<string,mixed>
     */
    public function toQuery(): array
    {
        return Payload::compact([
            'principal' => $this->principal,
            'key' => $this->key,
            'kind' => Payload::enum($this->kind),
            'since' => $this->since,
            'until' => $this->until,
            'limit' => $this->limit,
            'cursor' => $this->cursor,
        ]);
    }
}
