<?php

namespace SurrealDB\SDK\Scheduler\Swoole;

use OpenSwoole\Coroutine\Channel;
use SurrealDB\SDK\Contracts\Deferred;
use function is_array;

/**
 * A coroutine-backed {@see Deferred}. `await()` pops a single-slot channel,
 * suspending the current coroutine while the read-loop coroutine resolves it.
 *
 * Requires the OpenSwoole extension; only instantiated under the Swoole runtime.
 *
 * @template T
 * @implements Deferred<T>
 */
final class SwooleDeferred implements Deferred
{
    private readonly Channel $channel;
    private bool $settled = false;

    public function __construct()
    {
        $this->channel = new Channel(1);
    }

    /**
     * @param T $value
     */
    public function resolve(mixed $value): void
    {
        if (!$this->settled) {
            $this->settled = true;
            $this->channel->push(['ok', $value]);
        }
    }

    public function fail(\Throwable $error): void
    {
        if (!$this->settled) {
            $this->settled = true;
            $this->channel->push(['error', $error]);
        }
    }

    public function isPending(): bool
    {
        return !$this->settled;
    }

    /**
     * @return T
     */
    public function await(): mixed
    {
        $result = $this->channel->pop();

        if (is_array($result) && ($result[0] ?? null) === 'error') {
            throw $result[1];
        }

        return is_array($result) ? ($result[1] ?? null) : null;
    }
}
