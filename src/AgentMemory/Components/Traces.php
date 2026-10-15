<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Retrieval decision traces for a context. */
final class Traces
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /** A page of recent trace records, newest first. */
    public function list(?PageOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'traces', $options?->toQuery() ?? []);
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
     * Aggregate trace statistics over the server's trailing window; the
     * response's `windowHours` says which.
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
