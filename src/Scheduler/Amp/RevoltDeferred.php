<?php

namespace SurrealDB\Scheduler\Amp;

use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use SurrealDB\Contracts\Deferred;

/**
 * A fiber-backed {@see Deferred} on the Revolt event loop. `await()` suspends
 * the current fiber until the read-loop resumes it via resolve/fail.
 *
 * Requires revolt/event-loop; only instantiated under the Amp runtime.
 *
 * @template T
 * @implements Deferred<T>
 */
final class RevoltDeferred implements Deferred
{
    private bool $settled = false;
    /** @var T */
    private mixed $value;
    private ?\Throwable $error = null;
    /** @var Suspension<mixed>|null */
    private ?Suspension $suspension = null;

    /**
     * @param T $value
     */
    public function resolve(mixed $value): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->value = $value;
        $this->suspension?->resume();
    }

    public function fail(\Throwable $error): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->error = $error;
        $this->suspension?->resume();
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
        if (!$this->settled) {
            $this->suspension = EventLoop::getSuspension();
            $this->suspension->suspend();
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }
}
