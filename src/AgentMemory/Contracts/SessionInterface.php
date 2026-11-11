<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Schema\Schemas;

/**
 * An open conversation session within an Agent Memory context.
 *
 * @phpstan-import-type SessionContextResponseJson from Schemas
 * @phpstan-import-type TurnResponseJson from Schemas
 */
interface SessionInterface
{
    /** Session id (API path segment). */
    public string $id { get; }

    /** Creation timestamp; `null` for a session attached by id ({@see SessionsInterface::attach()}). */
    public ?string $createdAt { get; }

    /**
     * DNF scope selector the session writes to (outer OR, inner AND); `null`
     * for a session attached by id ({@see SessionsInterface::attach()}).
     *
     * @var list<list<string>>|null
     */
    public ?array $scopes { get; }

    /** Deletes this session on the server. */
    public function close(): void;

    /**
     * A page of the turns recorded against this session, within the caller's read region.
     *
     * @return Page<TurnResponseJson>
     */
    public function turns(?PageOptions $options = null): Page;

    /**
     * Every turn recorded against this session, walking the pages in order
     * (`GET /sessions/{id}/turns`). A transcript is usually read whole.
     *
     * @return list<TurnResponseJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     * @throws AgentMemoryException     when the request fails
     */
    public function allTurns(?PageOptions $options = null): array;

    /**
     * Retrieves session-scoped LLM context text for a query.
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does.
     *
     * @return SessionContextResponseJson
     *
     * @throws InvalidArgumentException when the query is blank, before any request is sent
     * @throws AgentMemoryException     when the request fails
     */
    public function context(string $query): array;
}
