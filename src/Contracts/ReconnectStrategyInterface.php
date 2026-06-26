<?php

namespace SurrealDB\SDK\Contracts;

/**
 * Governs whether and how a dropped connection is retried. Mirrors the JS
 * `ReconnectContext`.
 */
interface ReconnectStrategyInterface
{
    public function enabled(): bool;

    /** Whether another reconnect attempt is currently permitted. */
    public function allowed(): bool;

    /** Reset the attempt counter after a successful connection. */
    public function reset(): void;

    /**
     * Advance the attempt counter and return the number of seconds to wait
     * before the next attempt. The caller performs the (cooperative) wait,
     * typically via {@see Scheduler::delay()}, keeping backoff async-correct.
     */
    public function nextDelay(): float;

    public function attempts(): int;
}
