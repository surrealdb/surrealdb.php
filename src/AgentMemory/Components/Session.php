<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\SessionInterface;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;
use function is_array;
use function is_string;

/** Implements {@see SessionInterface} over the Agent Memory HTTP API. */
final class Session implements SessionInterface
{
    public readonly string $id;

    public readonly ?string $createdAt;

    /** @var list<list<string>>|null */
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

    public function close(): void
    {
        $this->transport->requestJson('DELETE', $this->base());
    }

    public function turns(?PageOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base() . '/turns', 'turns', $options?->toQuery() ?? []);
    }

    public function allTurns(?PageOptions $options = null): array
    {
        Guard::collectable('Session::allTurns()', 'Session::turns()', $options?->cursor, $options->count ?? false);

        return $this->turns($options)->collect();
    }

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
