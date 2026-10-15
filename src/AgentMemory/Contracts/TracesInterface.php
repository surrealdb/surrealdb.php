<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Schema\Schemas;

/**
 * Retrieval decision traces for a context.
 *
 * @phpstan-import-type TraceRecordJson from Schemas
 * @phpstan-import-type TraceStatsResponseJson from Schemas
 */
interface TracesInterface
{
    /**
     * A page of recent trace records, newest first.
     *
     * @return Page<TraceRecordJson>
     */
    public function list(?PageOptions $options = null): Page;

    /**
     * Every trace record, newest first, walking the pages in order
     * (`GET /traces`).
     *
     * @return list<TraceRecordJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function listAll(?PageOptions $options = null): array;

    /**
     * Fetches one trace by id.
     *
     * @return TraceRecordJson
     */
    public function get(string $traceId): array;

    /**
     * Aggregate trace statistics over the server's trailing window; the
     * response's `windowHours` says which.
     *
     * @return TraceStatsResponseJson
     */
    public function stats(): array;
}
