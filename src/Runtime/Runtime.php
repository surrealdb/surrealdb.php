<?php

namespace SurrealDB\SDK\Runtime;

use SurrealDB\SDK\Connection\ConnectionState;
use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Contracts\DuplexTransportInterface;
use SurrealDB\SDK\Contracts\TransportInterface;
use SurrealDB\SDK\Scheduler\Amp\RevoltScheduler;
use SurrealDB\SDK\Scheduler\Swoole\SwooleScheduler;
use SurrealDB\SDK\Scheduler\SyncScheduler;
use SurrealDB\SDK\Transport\Amp\AmpHttpTransport;
use SurrealDB\SDK\Transport\Amp\AmpWebSocketTransport;
use SurrealDB\SDK\Transport\Swoole\SwooleWebSocketTransport;

/**
 * One-line runtime presets that assemble the matching scheduler and transports
 * onto a {@see DriverOptions}. Pass the result to `new Surreal(...)`.
 *
 * The Swoole/Amp presets reference optional packages; install the relevant
 * suggested dependency (see composer.json) before using them.
 */
final class Runtime
{
    /** The default synchronous runtime (works everywhere; no extra dependencies). */
    public static function sync(?DriverOptions $options = null): DriverOptions
    {
        $options ??= new DriverOptions();
        $options->scheduler = new SyncScheduler();

        return $options;
    }

    /**
     * The OpenSwoole coroutine runtime. Enables runtime hooks so the default
     * PSR-18 HTTP transport becomes non-blocking; the WebSocket transport uses
     * OpenSwoole's native coroutine client. Use inside a `Co::run()` context.
     */
    public static function swoole(?DriverOptions $options = null, bool $enableHooks = true): DriverOptions
    {
        $options ??= new DriverOptions();

        if ($enableHooks && class_exists(\OpenSwoole\Runtime::class)) {
            \OpenSwoole\Runtime::enableCoroutine(true, \OpenSwoole\Runtime::HOOK_ALL);
        }

        $options->scheduler = new SwooleScheduler();
        $options->webSocketTransportFactory = static fn (DriverContext $context, ConnectionState $state): DuplexTransportInterface
            => new SwooleWebSocketTransport($context, $state->endpoint);

        return $options;
    }

    /**
     * The Amp / Revolt fiber runtime (e.g. FrankenPHP worker mode). Uses
     * non-blocking Amp WebSocket and HTTP transports. Requires a running event
     * loop.
     */
    public static function amp(?DriverOptions $options = null): DriverOptions
    {
        $options ??= new DriverOptions();
        $options->scheduler = new RevoltScheduler();
        $options->webSocketTransportFactory = static fn (DriverContext $context, ConnectionState $state): DuplexTransportInterface
            => new AmpWebSocketTransport($context, $state->endpoint);
        $options->httpTransportFactory = static fn (DriverContext $context, ConnectionState $state): TransportInterface
            => new AmpHttpTransport($context, $state);

        return $options;
    }
}
