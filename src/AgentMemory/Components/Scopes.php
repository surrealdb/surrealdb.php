<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Options\ScopeForgetOptions;
use SurrealDB\AgentMemory\Options\ScopeListOptions;
use SurrealDB\AgentMemory\Options\ScopeRegisterOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** The scope tree: register, list, delete, and forget scope subtrees. */
final class Scopes
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /** A page of the registered scope nodes the caller can see. */
    public function list(?ScopeListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'scopes', $options?->toQuery() ?? []);
    }

    /**
     * Registers a scope path with optional display metadata. Writes must target
     * a registered node, so register before writing; missing ancestors are
     * created too. Requires the `scope:create` grant.
     *
     * @return array<int|string,mixed>
     */
    public function register(ScopeRegisterOptions $options): array
    {
        return $this->transport->requestJson('POST', $this->base(), body: $options->toPayload()) ?? [];
    }

    /** Deletes (tombstones) a scope node by path; the root scope cannot be deleted. */
    public function delete(string $path): void
    {
        $this->transport->requestJson('DELETE', $this->base(), ['path' => $path]);
    }

    /**
     * Forgets (erases) a scope subtree. Returns the number of rows forgotten.
     *
     * @return array<int|string,mixed>
     */
    public function forget(?ScopeForgetOptions $options = null): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/forget', body: $options?->toPayload() ?? []) ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/scopes';
    }
}
