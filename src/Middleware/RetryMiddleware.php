<?php

namespace SurrealDB\Middleware;

use SurrealDB\Contracts\MiddlewareInterface;
use SurrealDB\Contracts\Scheduler;
use SurrealDB\Exceptions\CallTerminatedException;
use SurrealDB\Exceptions\ConnectionUnavailableException;
use SurrealDB\Exceptions\HttpConnectionException;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;
use SurrealDB\Scheduler\SyncScheduler;

/**
 * Retries calls that fail with transient connection errors, using exponential
 * backoff via the scheduler. Opt-in (not part of the default pipeline) since
 * blind retries are unsafe for non-idempotent operations.
 */
final class RetryMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly float $baseDelaySeconds = 0.1,
        private readonly Scheduler $scheduler = new SyncScheduler(),
    ) {}

    /**
     * @param callable(RpcRequest): RpcResponse<mixed> $next
     *
     * @return RpcResponse<mixed>
     */
    public function process(RpcRequest $request, callable $next): RpcResponse
    {
        $attempt = 0;

        while (true) {
            try {
                return $next($request);
            } catch (\Throwable $error) {
                $attempt++;

                if ($attempt >= $this->maxAttempts || !$this->isTransient($error)) {
                    throw $error;
                }

                $this->scheduler->delay($this->baseDelaySeconds * (2 ** ($attempt - 1)));
            }
        }
    }

    private function isTransient(\Throwable $error): bool
    {
        return $error instanceof HttpConnectionException
            || $error instanceof CallTerminatedException
            || $error instanceof ConnectionUnavailableException;
    }
}
