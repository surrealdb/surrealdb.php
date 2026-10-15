<?php

namespace SurrealDB\AgentMemory\Contracts;

/** Operator lifecycle sweeps (expiry and decay). */
interface LifecycleInterface
{
    /**
     * Runs the context-category expiry sweep. Returns the number of affected rows.
     *
     * @return array<int|string,mixed>
     */
    public function expire(): array;

    /**
     * Runs the context-wide importance decay sweep (requires a context
     * administrator). Returns the number of affected rows.
     *
     * @return array<int|string,mixed>
     */
    public function decay(): array;
}
