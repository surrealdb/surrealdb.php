<?php

namespace SurrealDB\Connection;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use SurrealDB\Codec\Codec;
use SurrealDB\Contracts\Meter;
use SurrealDB\Contracts\MiddlewareInterface;
use SurrealDB\Contracts\Scheduler;
use SurrealDB\Contracts\Tracer;
use SurrealDB\Enum\CodecEnum;

/**
 * Driver-wide configuration and dependency injection. Every field has a sane
 * default so `new Surreal()` works out of the box; override any to swap an
 * implementation (the construction-time injection seam).
 */
final class DriverOptions
{
    /**
     * @param array<string,callable|\SurrealDB\Contracts\EngineFactoryInterface>|null $engines
     *        Engine factory overrides keyed by URL scheme.
     * @param (\Closure(Endpoint, DriverContext): \SurrealDB\Contracts\WebSocketClientInterface)|null $webSocketClientFactory
     * @param (\Closure(DriverContext, \SurrealDB\Connection\ConnectionState): \SurrealDB\Contracts\DuplexTransportInterface)|null $webSocketTransportFactory
     *        Swap the entire WebSocket transport (e.g. an async Swoole/Amp implementation).
     * @param (\Closure(DriverContext, \SurrealDB\Connection\ConnectionState): \SurrealDB\Contracts\TransportInterface)|null $httpTransportFactory
     *        Swap the entire HTTP transport (e.g. a non-blocking Amp implementation).
     * @param list<MiddlewareInterface> $middleware Additional middleware appended to the pipeline.
     *
     * The `tracer` and `meter` are the telemetry seams (cf. `logger`): leave
     * them null for no-op defaults, or pass an adapter (OpenTelemetry, the PSR-3
     * bridge, or any custom implementation) to enable tracing/metrics.
     */
    public function __construct(
        public ?Codec $codec = null,
        public CodecEnum $format = CodecEnum::CBOR,
        public ?EventDispatcherInterface $events = null,
        public ?LoggerInterface $logger = null,
        public ?Tracer $tracer = null,
        public ?Meter $meter = null,
        public ?ClientInterface $httpClient = null,
        public ?RequestFactoryInterface $requestFactory = null,
        public ?StreamFactoryInterface $streamFactory = null,
        public ?Scheduler $scheduler = null,
        public ?array $engines = null,
        public ?\Closure $webSocketClientFactory = null,
        public ?\Closure $webSocketTransportFactory = null,
        public ?\Closure $httpTransportFactory = null,
        public array $middleware = [],
        public int $pingInterval = 30,
    ) {}
}
