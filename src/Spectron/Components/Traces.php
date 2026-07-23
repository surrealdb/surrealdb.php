<?php

namespace SurrealDB\Spectron\Components;

use SurrealDB\Spectron\Options\TraceListOptions;
use SurrealDB\Spectron\Paths;
use SurrealDB\Spectron\Transport;

/** Retrieval decision traces for a context. */
final class Traces
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /**
     * Lists recent trace records.
     *
     * @return list<array<string,mixed>>
     */
    public function list(?TraceListOptions $options = null): array
    {
        return ($this->transport->requestJson('GET', $this->base(), $options?->toQuery() ?? []) ?? [])['traces'] ?? [];
    }

    /**
     * Fetches one trace by id.
     *
     * @return array<int|string,mixed>
     */
    public function get(string $traceId): array
    {
        return $this->transport->requestJson('GET', $this->base() . '/' . Paths::encodeSegment($traceId)) ?? [];
    }

    /**
     * Aggregate trace statistics over the recent window.
     *
     * @return array<int|string,mixed>
     */
    public function stats(): array
    {
        return $this->transport->requestJson('GET', $this->base() . '/stats') ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/traces';
    }
}
