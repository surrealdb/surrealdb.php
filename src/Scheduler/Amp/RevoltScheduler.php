<?php

namespace SurrealDB\SDK\Scheduler\Amp;

use Revolt\EventLoop;
use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Contracts\TimerScheduler;

/**
 * Scheduler backed by the Revolt event loop and PHP fibers (the foundation of
 * Amp v3 and ReactPHP interop). Suited to FrankenPHP worker mode and other
 * long-running, fiber-based environments.
 *
 * Requires revolt/event-loop. The application is expected to run the event loop
 * (e.g. `Revolt\EventLoop::run()` or an Amp entrypoint).
 */
final class RevoltScheduler implements TimerScheduler
{
    public function spawn(\Closure $task): void
    {
        EventLoop::queue($task);
    }

    public function delay(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $suspension = EventLoop::getSuspension();
        EventLoop::delay($seconds, static fn () => $suspension->resume());
        $suspension->suspend();
    }

    /**
     * @return Deferred<mixed>
     */
    public function defer(?\Closure $drive = null): Deferred
    {
        return new RevoltDeferred();
    }

    public function after(float $seconds, \Closure $task): \Closure
    {
        // Callbacks run in a fiber of their own, as queued tasks do.
        $id = EventLoop::delay(max($seconds, 0.0), static function () use ($task): void {
            $task();
        });

        return static function () use ($id): void {
            EventLoop::cancel($id);
        };
    }
}
