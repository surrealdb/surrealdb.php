<?php

namespace SurrealDB\Spectron\Components;

use SurrealDB\Spectron\Options\ScopeForgetOptions;
use SurrealDB\Spectron\Options\ScopeRegisterOptions;
use SurrealDB\Spectron\Paths;
use SurrealDB\Spectron\Transport;

/** The scope tree: register, list, delete, and forget scope subtrees. */
final class Scopes
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /**
     * Lists registered scope nodes.
     *
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        return $this->transport->requestJson('GET', $this->base()) ?? [];
    }

    /**
     * Registers a scope path with optional display metadata.
     *
     * @return array<int|string,mixed>
     */
    public function register(ScopeRegisterOptions $options): array
    {
        return $this->transport->requestJson('POST', $this->base(), body: $options->toPayload()) ?? [];
    }

    /** Deletes (tombstones) a scope node by path. */
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
