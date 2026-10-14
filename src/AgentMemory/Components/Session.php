<?php

namespace SurrealDB\AgentMemory\Components;

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
     * Retrieves session-scoped LLM context text for a query.
     *
     * @return array<int|string,mixed>
     */
    public function context(string $query): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/context', body: ['query' => $query]) ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/sessions/' . Paths::encodeSegment($this->id);
    }
}
