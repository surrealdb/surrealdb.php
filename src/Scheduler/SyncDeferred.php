<?php

namespace SurrealDB\SDK\Scheduler;

use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Exceptions\CallTerminatedException;

/**
 * The synchronous {@see Deferred}: while awaiting, it repeatedly invokes the
 * drive callback (which pumps one inbound frame) until the slot is settled or
 * the connection closes.
 *
 * @template T
 * @implements Deferred<T>
 */
final class SyncDeferred implements Deferred
{
    private bool $settled = false;
    /** @var T */
    private mixed $value;
    private ?\Throwable $error = null;

    public function __construct(private readonly ?\Closure $drive = null) {}

    /**
     * @param T $value
     */
    public function resolve(mixed $value): void
    {
        if (!$this->settled) {
            $this->settled = true;
            $this->value = $value;
        }
    }

    public function fail(\Throwable $error): void
    {
        if (!$this->settled) {
            $this->settled = true;
            $this->error = $error;
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
        $drive = $this->drive;

        while (!$this->settled) {
            if ($drive === null || $drive() === false) {
                $this->fail(new CallTerminatedException());
                break;
            }
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }
}
