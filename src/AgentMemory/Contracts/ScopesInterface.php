<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\ScopeForgetOptions;
use SurrealDB\AgentMemory\Options\ScopeListOptions;
use SurrealDB\AgentMemory\Options\ScopeRegisterOptions;
use SurrealDB\AgentMemory\Page;

/** The scope tree: register, list, delete, and forget scope subtrees. */
interface ScopesInterface
{
    /** A page of the registered scope nodes the caller can see. */
    public function list(?ScopeListOptions $options = null): Page;

    /**
     * Every registered scope node the caller can see, walking the pages in
     * order (`GET /scopes`).
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor`
     */
    public function listAll(?ScopeListOptions $options = null): array;

    /**
     * Registers a scope path with optional display metadata. Writes must target
     * a registered node, so register before writing; missing ancestors are
     * created too. Requires the `scope:create` grant.
     *
     * @return array<int|string,mixed>
     */
    public function register(ScopeRegisterOptions $options): array;

    /** Deletes (tombstones) a scope node by path; the root scope cannot be deleted. */
    public function delete(string $path): void;

    /**
     * Forgets (erases) the scope subtree at the options' `path`. The server
     * requires a path and answers 400 without one; it never forgets the whole
     * context. Returns the number of rows forgotten.
     *
     * @return array<int|string,mixed>
     */
    public function forget(?ScopeForgetOptions $options = null): array;
}
