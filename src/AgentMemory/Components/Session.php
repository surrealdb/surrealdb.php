<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;
use function is_array;
use function is_string;

/** An open conversation session within an Agent Memory context. */
final class Session
{
    /** Session id (API path segment). */
    public readonly string $id;

    /** Creation timestamp; `null` for a session attached by id ({@see Sessions::attach()}). */
    public readonly ?string $createdAt;

    /**
     * DNF scope selector the session writes to (outer OR, inner AND); `null`
     * for a session attached by id ({@see Sessions::attach()}).
     *
     * @var list<list<string>>|null
     */
    public readonly ?array $scopes;

    /**
     * @param array<string,mixed> $info the `SessionResponse` payload returned on creation, or just `['id' => …]`
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
        array $info,
    ) {
        $this->id = (string) ($info['id'] ?? '');
        $this->createdAt = is_string($info['createdAt'] ?? null) ? $info['createdAt'] : null;
        $this->scopes = is_array($info['scopes'] ?? null) ? $info['scopes'] : null;
    }

    /** Deletes this session on the server. */
    public function close(): void
    {
        $this->transport->requestJson('DELETE', $this->base());
    }

    /** A page of the turns recorded against this session, within the caller's read region. */
    public function turns(?PageOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base() . '/turns', 'turns', $options?->toQuery() ?? []);
    }

    /**
     * Every turn recorded against this session, walking the pages in order
     * (`GET /sessions/{id}/turns`). A transcript is usually read whole.
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function allTurns(?PageOptions $options = null): array
    {
        Guard::collectable('Session::allTurns()', 'Session::turns()', $options?->cursor, $options->count ?? false);

        return $this->turns($options)->collect();
    }

    /**
     * Retrieves session-scoped LLM context text for a query.
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does.
     *
     * @return array<int|string,mixed>
     */
    public function context(string $query): array
    {
        Guard::notBlank($query, 'Session::context() requires a non-empty query.');

        return $this->transport->requestJson('POST', $this->base() . '/context', body: ['query' => $query], idempotent: true) ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/sessions/' . Paths::encodeSegment($this->id);
    }
}
