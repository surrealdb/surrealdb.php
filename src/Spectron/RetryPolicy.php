<?php

namespace SurrealDB\Spectron;

use SurrealDB\Contracts\Scheduler;
use SurrealDB\Scheduler\SyncScheduler;

/**
 * Decides whether a failed request is retried and how long to back off.
 *
 * Retries apply to idempotent reads (`GET`/`HEAD`) and writes explicitly marked
 * idempotent, on `5xx` responses or connection errors (`status === null`), with
 * a fixed back-off schedule of 250ms, 500ms, 1000ms.
 *
 * Back-off waits go through the SDK-wide {@see Scheduler} seam so async
 * runtimes stay cooperative: the default {@see SyncScheduler} blocks, while the
 * Swoole/Revolt schedulers yield to their event loop instead of stalling every
 * other coroutine or fiber.
 */
final class RetryPolicy
{
    /** Back-off delays (ms) used between retry attempts. */
    private const array BACKOFF_MS = [250, 500, 1000];

    private readonly Scheduler $scheduler;

    public function __construct(
        private readonly int $maxRetries,
        ?Scheduler $scheduler = null,
    ) {
        $this->scheduler = $scheduler ?? new SyncScheduler();
    }

    /**
     * @param int|null $status HTTP status of the failure, or `null` for connection errors
     */
    public function shouldRetry(string $method, ?int $status, int $attempt, bool $idempotent = false): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        $method = strtoupper($method);

        if ($method !== 'GET' && $method !== 'HEAD' && !$idempotent) {
            return false;
        }

        return $status === null || $status >= 500;
    }

    /** Cooperatively waits for the back-off delay belonging to the given attempt. */
    public function backOff(int $attempt): void
    {
        $delayMs = self::BACKOFF_MS[$attempt] ?? 1000;

        $this->scheduler->delay($delayMs / 1000);
    }
}
