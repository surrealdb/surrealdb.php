<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\TracesInterface;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Implements {@see TracesInterface} over the Agent Memory HTTP API. */
final class Traces implements TracesInterface
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    public function list(?PageOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'traces', $options?->toQuery() ?? []);
    }

    public function listAll(?PageOptions $options = null): array
    {
        Guard::collectable('Traces::listAll()', 'Traces::list()', $options?->cursor, $options->count ?? false);

        return $this->list($options)->collect();
    }

    public function get(string $traceId): array
    {
        return $this->transport->requestJson('GET', $this->base() . '/' . Paths::encodeSegment($traceId)) ?? [];
    }

    public function stats(): array
    {
        return $this->transport->requestJson('GET', $this->base() . '/stats') ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/traces';
    }
}
