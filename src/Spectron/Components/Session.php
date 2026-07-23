<?php

namespace SurrealDB\Spectron\Components;

use SurrealDB\Spectron\Paths;
use SurrealDB\Spectron\Transport;

/** An open conversation session within a Spectron context. */
final class Session
{
    /** Session id (API path segment). */
    public readonly string $id;

    /** Creation timestamp. */
    public readonly string $createdAt;

    /**
     * DNF scope selector the session writes to (outer OR, inner AND).
     *
     * @var list<list<string>>
     */
    public readonly array $scopes;

    /**
     * @param array<string,mixed> $info the `SessionResponse` payload returned on creation
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
        array $info,
    ) {
        $this->id = (string) ($info['id'] ?? '');
        $this->createdAt = (string) ($info['createdAt'] ?? '');
        $this->scopes = (array) ($info['scopes'] ?? []);
    }

    /** Deletes this session on the server. */
    public function close(): void
    {
        $this->transport->requestJson('DELETE', $this->base());
    }

    /**
     * Lists turns recorded against this session.
     *
     * @return list<array<string,mixed>>
     */
    public function turns(): array
    {
        return ($this->transport->requestJson('GET', $this->base() . '/turns') ?? [])['turns'] ?? [];
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
