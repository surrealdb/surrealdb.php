<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\PrincipalsInterface;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\EffectiveGrantsOptions;
use SurrealDB\AgentMemory\Options\GrantOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Implements {@see PrincipalsInterface} over the Agent Memory HTTP API. */
final class Principals implements PrincipalsInterface
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    public function list(?PageOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'principals', $options?->toQuery() ?? []);
    }

    public function listAll(?PageOptions $options = null): array
    {
        Guard::collectable('Principals::listAll()', 'Principals::list()', $options?->cursor, $options->count ?? false);

        return $this->list($options)->collect();
    }

    public function get(string $principalId): array
    {
        return $this->transport->requestJson('GET', $this->path($principalId)) ?? [];
    }

    public function effective(string $principalId, EffectiveGrantsOptions $options): array
    {
        return $this->transport->requestJson(
            'GET',
            $this->path($principalId) . '/effective',
            $options->toQuery(),
        ) ?? [];
    }

    public function grant(string $principalId, GrantOptions $options): array
    {
        return $this->transport->requestJson(
            'POST',
            $this->path($principalId) . '/grants',
            body: $options->toPayload(),
        ) ?? [];
    }

    public function revoke(string $principalId, GrantOptions $options): array
    {
        return $this->transport->requestJson(
            'DELETE',
            $this->path($principalId) . '/grants',
            body: $options->toPayload(),
        ) ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/principals';
    }

    private function path(string $principalId): string
    {
        return $this->base() . '/' . Paths::encodeSegment($principalId);
    }
}
