<?php

namespace SurrealDB\SDK\Scheduler\Amp;

use Revolt\EventLoop;
use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Contracts\Scheduler;

/**
 * Scheduler backed by the Revolt event loop and PHP fibers (the foundation of
 * Amp v3 and ReactPHP interop). Suited to FrankenPHP worker mode and other
 * long-running, fiber-based environments.
 *
 * Requires revolt/event-loop. The application is expected to run the event loop
 * (e.g. `Revolt\EventLoop::run()` or an Amp entrypoint).
 */
final class RevoltScheduler implements Scheduler
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
}
