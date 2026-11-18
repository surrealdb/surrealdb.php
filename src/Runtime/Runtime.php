<?php

namespace SurrealDB\Runtime;

use SurrealDB\Connection\ConnectionState;
use SurrealDB\Connection\DriverContext;
use SurrealDB\Connection\DriverOptions;
use SurrealDB\Contracts\DuplexTransportInterface;
use SurrealDB\Contracts\TransportInterface;
use SurrealDB\Scheduler\Amp\RevoltScheduler;
use SurrealDB\Scheduler\Swoole\SwooleScheduler;
use SurrealDB\Scheduler\SyncScheduler;
use SurrealDB\Telemetry\OpenTelemetry\ObservabilityOptions;
use SurrealDB\Telemetry\OpenTelemetry\OtelObservability;
use SurrealDB\Transport\Amp\AmpHttpTransport;
use SurrealDB\Transport\Amp\AmpWebSocketTransport;
use SurrealDB\Transport\Swoole\SwooleWebSocketTransport;
use function function_exists;

/**
 * One-line runtime presets that assemble the matching scheduler and transports
 * onto a {@see DriverOptions}. Pass the result to `new Surreal(...)`.
 *
 * The Swoole/Amp presets reference optional packages; install the relevant
 * suggested dependency (see composer.json) before using them.
 *
 * Each preset optionally accepts {@see ObservabilityOptions} to wire
 * OpenTelemetry with the export strategy that fits the runtime: the synchronous
 * preset buffers spans and flushes after the request, while the async presets
 * export directly through a non-blocking transport (see {@see OtelObservability}).
 */
final class Runtime
{
    /**
     * The default synchronous runtime (works everywhere; no extra dependencies).
     *
     * With `$observability` set, spans are buffered in a batch processor and
     * flushed once the request finishes (after `fastcgi_finish_request()` under
     * FPM), keeping export off the user-visible request path.
     */
    public static function sync(?DriverOptions $options = null, ?ObservabilityOptions $observability = null): DriverOptions
    {
        $options ??= new DriverOptions();
        $options->scheduler = new SyncScheduler();

        if ($observability !== null) {
            $telemetry = OtelObservability::batched($observability);
            $options->tracer = $telemetry->tracer();
            $options->meter = $telemetry->meter();
            self::flushAfterRequest($telemetry, $observability->finishRequestBeforeFlush);
        }

        return $options;
    }

    /**
     * The OpenSwoole coroutine runtime. Enables runtime hooks so the default
     * PSR-18 HTTP transport becomes non-blocking; the WebSocket transport uses
     * OpenSwoole's native coroutine client. Use inside a `Co::run()` context.
     *
     * With `$observability` set, each span is exported directly (no batching);
     * the enabled runtime hooks make that export non-blocking.
     */
    public static function swoole(?DriverOptions $options = null, bool $enableHooks = true, ?ObservabilityOptions $observability = null): DriverOptions
    {
        $options ??= new DriverOptions();

        if ($enableHooks && class_exists(\OpenSwoole\Runtime::class)) {
            \OpenSwoole\Runtime::enableCoroutine(true, \OpenSwoole\Runtime::HOOK_ALL);
        }

        $options->scheduler = new SwooleScheduler();
        $options->webSocketTransportFactory = static fn (DriverContext $context, ConnectionState $state): DuplexTransportInterface
            => new SwooleWebSocketTransport($context, $state->endpoint);

        if ($observability !== null) {
            $telemetry = OtelObservability::direct($observability);
            $options->tracer = $telemetry->tracer();
            $options->meter = $telemetry->meter();
        }

        return $options;
    }

    /**
     * The Amp / Revolt fiber runtime (e.g. FrankenPHP worker mode). Uses
     * non-blocking Amp WebSocket and HTTP transports. Requires a running event
     * loop.
     *
     * With `$observability` set, each span is exported directly (no batching)
     * through a non-blocking Amp PSR-18 client so the export yields on the loop.
     */
    public static function amp(?DriverOptions $options = null, ?ObservabilityOptions $observability = null): DriverOptions
    {
        $options ??= new DriverOptions();
        $options->scheduler = new RevoltScheduler();
        $options->webSocketTransportFactory = static fn (DriverContext $context, ConnectionState $state): DuplexTransportInterface
            => new AmpWebSocketTransport($context, $state->endpoint);
        $options->httpTransportFactory = static fn (DriverContext $context, ConnectionState $state): TransportInterface
            => new AmpHttpTransport($context, $state);

        if ($observability !== null) {
            $telemetry = OtelObservability::direct($observability, OtelObservability::ampHttpClient());
            $options->tracer = $telemetry->tracer();
            $options->meter = $telemetry->meter();
        }

        return $options;
    }

    /**
     * Register a shutdown hook that drains buffered telemetry after the request.
     * Under FPM the response is first sent to the client with
     * `fastcgi_finish_request()` so the export never adds to user-visible latency.
     */
    private static function flushAfterRequest(OtelObservability $telemetry, bool $finishRequest): void
    {
        register_shutdown_function(static function () use ($telemetry, $finishRequest): void {
            if ($finishRequest && PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $telemetry->shutdown();
        });
    }
}
