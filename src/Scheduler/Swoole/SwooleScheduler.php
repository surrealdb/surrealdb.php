<?php

namespace SurrealDB\SDK\Scheduler\Swoole;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\System;
use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Contracts\Scheduler;

/**
 * Scheduler backed by OpenSwoole coroutines. Background tasks become real
 * coroutines, delays are cooperative, and deferreds use channels — so the
 * WebSocket engine multiplexes concurrent calls without code changes.
 *
 * Must be used inside a coroutine context (e.g. `Co::run()` or an OpenSwoole
 * server request). Requires the OpenSwoole extension.
 */
final class SwooleScheduler implements Scheduler
{
    public function spawn(\Closure $task): void
    {
        Coroutine::create($task);
    }

    public function delay(float $seconds): void
    {
        if ($seconds > 0) {
            System::usleep((int) round($seconds * 1_000_000));
        }
    }

    /**
     * @return Deferred<mixed>
     */
    public function defer(?\Closure $drive = null): Deferred
    {
        return new SwooleDeferred();
    }
}
