<?php

namespace SurrealDB\Spectron;

use Psr\Http\Client\ClientInterface;
use SurrealDB\Contracts\Scheduler;
use SurrealDB\Http\AmpPsr18Client;
use SurrealDB\Scheduler\Amp\RevoltScheduler;
use SurrealDB\Scheduler\Swoole\SwooleScheduler;
use SurrealDB\Scheduler\SyncScheduler;
use SurrealDB\Spectron;

/**
 * The runtime profile a {@see Spectron} client runs on: the {@see Scheduler}
 * that keeps cooperative waits (retry back-off) from blocking the host
 * runtime, and an optional PSR-18 client tuned for it.
 *
 * Mirrors the driver-wide {@see \SurrealDB\Runtime\Runtime} presets — pass
 * one to {@see SpectronOptions} to run the client on OpenSwoole coroutines or
 * the Amp/Revolt fiber runtime (e.g. FrankenPHP worker mode):
 *
 * ```php
 * $client = new Spectron(new SpectronOptions(
 *     endpoint: 'https://spectron.example.com',
 *     context: 'acme-prod',
 *     apiKey: $key,
 *     runtime: SpectronRuntime::amp(),
 * ));
 * ```
 *
 * The presets referencing optional packages (Swoole, Amp) require the relevant
 * suggested dependency (see composer.json). A custom profile is just a value:
 * construct one with your own scheduler and PSR-18 client to target any other
 * runtime without touching the client (Open/Closed).
 */
final readonly class SpectronRuntime
{
    /**
     * @param Scheduler            $scheduler  performs cooperative waits (retry back-off)
     * @param ClientInterface|null $httpClient runtime-tuned PSR-18 client, or `null` to use
     *                                         the discovered/injected blocking client
     */
    public function __construct(
        public Scheduler $scheduler,
        public ?ClientInterface $httpClient = null,
    ) {}

    /**
     * The default synchronous runtime (works everywhere; no extra dependencies).
     * Requests block, and retry back-off sleeps the process.
     */
    public static function sync(): self
    {
        return new self(new SyncScheduler());
    }

    /**
     * The OpenSwoole coroutine runtime. Enables runtime hooks so the discovered
     * PSR-18 client becomes non-blocking inside coroutines, and retry back-off
     * yields via `Coroutine\System::usleep()` instead of stalling the worker.
     * Use inside a `Co::run()` context.
     */
    public static function swoole(bool $enableHooks = true): self
    {
        if ($enableHooks && class_exists(\OpenSwoole\Runtime::class)) {
            \OpenSwoole\Runtime::enableCoroutine(true, \OpenSwoole\Runtime::HOOK_ALL);
        }

        return new self(new SwooleScheduler());
    }

    /**
     * The Amp / Revolt fiber runtime (e.g. FrankenPHP worker mode). Requests go
     * through a non-blocking Amp PSR-18 client that suspends the current fiber,
     * response bodies stream lazily (so {@see Spectron::chatStream()} yields to
     * the loop between SSE frames), and retry back-off waits on the event loop.
     * Requires amphp/http-client-psr7 and a running event loop.
     *
     * @param ClientInterface|null $httpClient override the Amp-backed PSR-18
     *                                         client (primarily for tests)
     */
    public static function amp(?ClientInterface $httpClient = null): self
    {
        return new self(new RevoltScheduler(), $httpClient ?? AmpPsr18Client::create());
    }
}
