<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;

/** An open conversation session within an Agent Memory context. */
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

    /** A page of the turns recorded against this session, within the caller's read region. */
    public function turns(?PageOptions $options = null): Page;

    /**
     * Every turn recorded against this session, walking the pages in order
     * (`GET /sessions/{id}/turns`). A transcript is usually read whole.
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function allTurns(?PageOptions $options = null): array;

    /**
     * Retrieves session-scoped LLM context text for a query.
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does.
     *
     * @return array<int|string,mixed>
     */
    public function context(string $query): array;
}
