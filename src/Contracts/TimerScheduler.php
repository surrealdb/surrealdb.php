<?php

namespace SurrealDB\SDK\Contracts;

/**
 * A scheduler that can run a task after a delay and cancel it before then.
 *
 * Background timers (token renewal, keepalive pings) use it when available,
 * so that closing a connection cancels them. Otherwise they are tasks that
 * sleep with {@see Scheduler::delay()}, which keep an event loop or coroutine
 * runtime from finishing until they wake.
 */
interface TimerScheduler extends Scheduler
{
    /**
     * Run `$task` as its own task once `$seconds` have passed.
     *
     * @return \Closure(): void cancels the task if it has not started
     */
    public function after(float $seconds, \Closure $task): \Closure;
}
