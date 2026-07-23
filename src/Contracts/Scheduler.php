<?php

namespace SurrealDB\Contracts;

/**
 * The cooperative scheduling seam that keeps the engine runtime-agnostic.
 *
 * A synchronous implementation ships by default; async runtimes (OpenSwoole
 * coroutines, Amp/Revolt fibers) provide drop-in implementations so the engine
 * never changes. It provides exactly the three concepts the engine needs that
 * synchronous PHP lacks: background tasks, cooperative delays, and awaitable
 * slots for correlating in-flight RPC responses.
 */
interface Scheduler
{
    /**
     * Run a long-lived background task (e.g. the WebSocket read loop or pinger).
     * Synchronous implementations may treat this as a no-op, driving inbound
     * frames through {@see defer()}'s drive callback instead.
     */
    public function spawn(\Closure $task): void;

    /** Cooperatively wait for the given number of seconds. */
    public function delay(float $seconds): void;

    /**
     * Create an awaitable slot for a single RPC response.
     *
     * @param \Closure|null $drive A callback a synchronous implementation invokes
     *                             repeatedly while awaiting (pumping one inbound
     *                             frame per call, returning false when closed).
     *
     * @return Deferred<mixed>
     */
    public function defer(?\Closure $drive = null): Deferred;
}
