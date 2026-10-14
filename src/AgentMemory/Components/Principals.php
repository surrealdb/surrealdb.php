<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Options\EffectiveGrantsOptions;
use SurrealDB\AgentMemory\Options\GrantOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Principals and their scope grants (requires the `grant:manage` grant). */
final class Principals
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /** A page of the context's principals. */
    public function list(?PageOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'principals', $options?->toQuery() ?? []);
    }

    /**
     * Fetches a single principal and its declared grants.
     *
     * @return array<int|string,mixed>
     */
    public function get(string $principalId): array
    {
        return $this->transport->requestJson('GET', $this->path($principalId)) ?? [];
    }

    /**
     * Resolves the verbs a principal effectively holds at a scope path.
     *
     * @return array<int|string,mixed>
     */
    public function effective(string $principalId, EffectiveGrantsOptions $options): array
    {
        return $this->transport->requestJson(
            'GET',
            $this->path($principalId) . '/effective',
            $options->toQuery(),
        ) ?? [];
    }

    /**
     * Grants a principal a set of verbs over a scope pattern.
     *
     * @return array<int|string,mixed>
     */
    public function grant(string $principalId, GrantOptions $options): array
    {
        return $this->transport->requestJson(
            'POST',
            $this->path($principalId) . '/grants',
            body: $options->toPayload(),
        ) ?? [];
    }

    /**
     * Revokes a set of verbs from a principal over a scope pattern.
     *
     * @return array<int|string,mixed>
     */
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
