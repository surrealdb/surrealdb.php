<?php

namespace SurrealDB\Scheduler;

use SurrealDB\Contracts\Deferred;
use SurrealDB\Contracts\Scheduler;

/**
 * The default, blocking scheduler. Background tasks are not run (synchronous
 * PHP has a single thread); instead inbound WebSocket frames are pumped through
 * the drive callback that {@see defer()} hands to each {@see SyncDeferred}.
 *
 * This is the Tier 0 implementation: the engine is written against the
 * {@see Scheduler} seam so async runtimes drop in with zero engine changes.
 */
final class SyncScheduler implements Scheduler
{
    public function spawn(\Closure $task): void
    {
        // Intentionally a no-op: see class docblock.
    }

    public function delay(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    /**
     * @return Deferred<mixed>
     */
    public function defer(?\Closure $drive = null): Deferred
    {
        return new SyncDeferred($drive);
    }
}
