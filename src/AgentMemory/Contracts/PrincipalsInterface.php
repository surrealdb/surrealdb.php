<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\EffectiveGrantsOptions;
use SurrealDB\AgentMemory\Options\GrantOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;

/** Principals and their scope grants (requires the `grant:manage` grant). */
interface PrincipalsInterface
{
    /** A page of the context's principals. */
    public function list(?PageOptions $options = null): Page;

    /**
     * Every principal in the context, walking the pages in order
     * (`GET /principals`).
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function listAll(?PageOptions $options = null): array;

    /**
     * Fetches a single principal and its declared grants.
     *
     * @return array<int|string,mixed>
     */
    public function get(string $principalId): array;

    /**
     * Resolves the verbs a principal effectively holds at a scope path.
     *
     * @return array<int|string,mixed>
     */
    public function effective(string $principalId, EffectiveGrantsOptions $options): array;

    /**
     * Grants a principal a set of verbs over a scope pattern.
     *
     * @return array<int|string,mixed>
     */
    public function grant(string $principalId, GrantOptions $options): array;

    /**
     * Revokes a set of verbs from a principal over a scope pattern.
     *
     * @return array<int|string,mixed>
     */
    public function revoke(string $principalId, GrantOptions $options): array;
}
