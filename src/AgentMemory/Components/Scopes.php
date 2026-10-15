<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\ScopesInterface;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\ScopeForgetOptions;
use SurrealDB\AgentMemory\Options\ScopeListOptions;
use SurrealDB\AgentMemory\Options\ScopeRegisterOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Implements {@see ScopesInterface} over the Agent Memory HTTP API. */
final class Scopes implements ScopesInterface
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    public function list(?ScopeListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'scopes', $options?->toQuery() ?? []);
    }

    public function listAll(?ScopeListOptions $options = null): array
    {
        Guard::collectable('Scopes::listAll()', 'Scopes::list()', $options?->cursor);

        return $this->list($options)->collect();
    }

    public function register(ScopeRegisterOptions $options): array
    {
        return $this->transport->requestJson('POST', $this->base(), body: $options->toPayload()) ?? [];
    }

    public function delete(string $path): void
    {
        Guard::notBlank($path, 'Scopes::delete() requires a scope path; an empty path addresses the root scope, which cannot be deleted.');

        $this->transport->requestJson('DELETE', $this->base(), ['path' => $path]);
    }

    public function forget(?ScopeForgetOptions $options = null): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/forget', body: $options?->toPayload() ?? []) ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/scopes';
    }
}
