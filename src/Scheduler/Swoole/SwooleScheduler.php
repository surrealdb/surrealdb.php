<?php

namespace SurrealDB\Scheduler\Swoole;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\System;
use OpenSwoole\Timer;
use SurrealDB\Contracts\Deferred;
use SurrealDB\Contracts\TimerScheduler;

/**
 * Scheduler backed by OpenSwoole coroutines. Background tasks become real
 * coroutines, delays are cooperative, and deferreds use channels — so the
 * WebSocket engine multiplexes concurrent calls without code changes.
 *
 * Must be used inside a coroutine context (e.g. `Co::run()` or an OpenSwoole
 * server request). Requires the OpenSwoole extension.
 */
final class SwooleScheduler implements TimerScheduler
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

    public function after(float $seconds, \Closure $task): \Closure
    {
        $id = Timer::after(max((int) round($seconds * 1000), 1), static function () use ($task): void {
            Coroutine::create($task);
        });

        return static function () use ($id): void {
            if ($id !== false) {
                // The extension's arginfo lists no parameters, but it takes the timer id.
                // @phpstan-ignore arguments.count
                Timer::clear($id);
            }
        };
    }
}
