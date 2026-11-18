<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Schema\Schemas;

/**
 * Operator lifecycle sweeps (expiry and decay).
 *
 * @phpstan-import-type LifecycleResponseJson from Schemas
 */
interface LifecycleInterface
{
    /**
     * Runs the context-category expiry sweep. Returns the number of affected rows.
     *
     * @return LifecycleResponseJson
     */
    public function expire(): array;

    /**
     * Runs the context-wide importance decay sweep (requires a context
     * administrator). Returns the number of affected rows.
     *
     * @return LifecycleResponseJson
     */
    public function decay(): array;
}
