<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\Contracts\Deferred;
use SurrealDB\Contracts\Scheduler;
use SurrealDB\Scheduler\SyncDeferred;

/** Records cooperative delays instead of sleeping. */
final class RecordingScheduler implements Scheduler
{
    /** @var list<float> */
    public array $delays = [];

    /** @var list<\Closure> */
    public array $spawned = [];

    public function spawn(\Closure $task): void
    {
        $this->spawned[] = $task;
    }

    public function delay(float $seconds): void
    {
        $this->delays[] = $seconds;
    }

    /**
     * @return Deferred<mixed>
     */
    public function defer(?\Closure $drive = null): Deferred
    {
        return new SyncDeferred($drive);
    }
}
