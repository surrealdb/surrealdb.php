<?php

namespace SurrealDB\AgentMemory;

use SurrealDB\Contracts\Scheduler;
use SurrealDB\Scheduler\SyncScheduler;
use function in_array;

/**
 * Decides whether a failed request is retried and how long to back off.
 *
 * - `503` is retried for every request: the server sends it when it sheds load
 *   or a dependency was unreachable, and guarantees no work was done.
 * - Idempotent reads (`GET`/`HEAD`) and requests marked idempotent (the
 *   `/facts` writes and the read-only POSTs) are also retried on connection
 *   errors (`status === null`) and on the other statuses that can heal on
 *   their own: `408`, `429`, `500`, `502`, `504`.
 * - An idempotent write that meets `409` because the first attempt is still
 *   running under the same `Idempotency-Key` is retried, so it can collect the
 *   stored result once that attempt finishes.
 *
 * Back-off follows a fixed schedule of 250ms, 500ms, 1000ms, raised to the
 * server's `Retry-After` when it sends one (capped at 4s, so a server cannot
 * stall the client). Waits go through the SDK-wide {@see Scheduler} seam so
 * async runtimes stay cooperative: the default {@see SyncScheduler} blocks,
 * while the Swoole/Revolt schedulers yield to their event loop instead of
 * stalling every other coroutine or fiber.
 *
 * Public, like `shouldRetry()` and `backoffSchedule()` in `@surrealdb/memory`,
 * for a caller building its own {@see Transport}.
 */
final class RetryPolicy
{
    /** Back-off delays (ms) used between retry attempts. */
    private const array BACKOFF_MS = [250, 500, 1000];

    /** Ceiling (ms) on a server-requested `Retry-After` wait. */
    private const int MAX_RETRY_AFTER_MS = 4000;

    /** Statuses worth retrying for idempotent requests; `501`, `505`, … never heal. */
    private const array RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    /** The server's message when an `Idempotency-Key` is claimed by a request still running. */
    private const string IN_FLIGHT_MESSAGE = 'already in progress';

    private readonly Scheduler $scheduler;

    public function __construct(
        private readonly int $maxRetries,
        ?Scheduler $scheduler = null,
    ) {
        $this->scheduler = $scheduler ?? new SyncScheduler();
    }

    /**
     * @param int|null $status HTTP status of the failure, or `null` for connection errors
     * @param string   $body   the failed response's body, used to tell an in-flight `409` from a real conflict
     */
    public function shouldRetry(string $method, ?int $status, int $attempt, bool $idempotent = false, string $body = ''): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        if ($status === 503) {
            return true;
        }

        $method = strtoupper($method);

        if ($method !== 'GET' && $method !== 'HEAD' && !$idempotent) {
            return false;
        }

        if ($status === 409) {
            return $idempotent && str_contains(strtolower($body), self::IN_FLIGHT_MESSAGE);
        }

        return $status === null || in_array($status, self::RETRYABLE_STATUSES, true);
    }

    /**
     * Cooperatively waits for the back-off delay belonging to the given attempt.
     *
     * @param int|null $retryAfterSeconds the failed response's `Retry-After`, when it sent one
     */
    public function backOff(int $attempt, ?int $retryAfterSeconds = null): void
    {
        $delayMs = self::BACKOFF_MS[$attempt] ?? 1000;

        if ($retryAfterSeconds !== null) {
            $delayMs = max($delayMs, min(self::MAX_RETRY_AFTER_MS, $retryAfterSeconds * 1000));
        }

        $this->scheduler->delay($delayMs / 1000);
    }
}
