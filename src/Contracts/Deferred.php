<?php

namespace SurrealDB\SDK\Contracts;

/**
 * An awaitable, single-assignment slot produced by a {@see Scheduler}. Used to
 * park a caller until its correlated RPC response arrives.
 *
 * @template T
 */
interface Deferred
{
    /**
     * @param T $value
     */
    public function resolve(mixed $value): void;

    public function fail(\Throwable $error): void;

    public function isPending(): bool;

    /**
     * Block until the slot is resolved (returning its value) or failed
     * (throwing its error).
     *
     * @return T
     */
    public function await(): mixed;
}
