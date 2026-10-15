<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;

/** Retrieval decision traces for a context. */
interface TracesInterface
{
    /** A page of recent trace records, newest first. */
    public function list(?PageOptions $options = null): Page;

    /**
     * Every trace record, newest first, walking the pages in order
     * (`GET /traces`).
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function listAll(?PageOptions $options = null): array;

    /**
     * Fetches one trace by id.
     *
     * @return array<int|string,mixed>
     */
    public function get(string $traceId): array;

    /**
     * Aggregate trace statistics over the server's trailing window; the
     * response's `windowHours` says which.
     *
     * @return array<int|string,mixed>
     */
    public function stats(): array;
}
