<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\ScopeForgetOptions;
use SurrealDB\AgentMemory\Options\ScopeListOptions;
use SurrealDB\AgentMemory\Options\ScopeRegisterOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Schema\Schemas;

/**
 * The scope tree: register, list, delete, and forget scope subtrees.
 *
 * @phpstan-import-type ForgetScopeResponseJson from Schemas
 * @phpstan-import-type ScopeNodeJson from Schemas
 */
interface ScopesInterface
{
    /**
     * A page of the registered scope nodes the caller can see.
     *
     * @return Page<ScopeNodeJson>
     */
    public function list(?ScopeListOptions $options = null): Page;

    /**
     * Every registered scope node the caller can see, walking the pages in
     * order (`GET /scopes`).
     *
     * @return list<ScopeNodeJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor`
     */
    public function listAll(?ScopeListOptions $options = null): array;

    /**
     * Registers a scope path with optional display metadata. Writes must target
     * a registered node, so register before writing; missing ancestors are
     * created too. Requires the `scope:create` grant.
     *
     * @return ScopeNodeJson
     */
    public function register(ScopeRegisterOptions $options): array;

    /**
     * Deletes (tombstones) a scope node by path; the root scope cannot be deleted.
     *
     * @throws InvalidArgumentException when the path is blank, which would address the root scope, before any
     *                                  request is sent
     */
    public function delete(string $path): void;

    /**
     * Forgets (erases) the scope subtree at the options' `path`. The server
     * requires a path and answers 400 without one; it never forgets the whole
     * context. Returns the number of rows forgotten.
     *
     * @return ForgetScopeResponseJson
     */
    public function forget(?ScopeForgetOptions $options = null): array;
}
