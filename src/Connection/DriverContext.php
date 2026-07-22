<?php

namespace SurrealDB\SDK\Connection;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SurrealDB\SDK\Codec\Codec;
use SurrealDB\SDK\Contracts\Meter;
use SurrealDB\SDK\Contracts\Scheduler;
use SurrealDB\SDK\Contracts\Tracer;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Telemetry\NullMeter;
use SurrealDB\SDK\Telemetry\NullTracer;

/**
 * The resolved dependency bundle passed to every engine, transport, and
 * middleware. Mirrors the JS `DriverContext`, expanded with the PSR seams
 * (event dispatcher, logger) and the scheduler.
 */
final readonly class DriverContext
{
    public function __construct(
        public DriverOptions $options,
        public Codec $codec,
        public CodecEnum $format,
        public EventDispatcherInterface $events,
        public LoggerInterface $logger,
        public Scheduler $scheduler,
        public \Closure $uniqueId,
        public Tracer $tracer = new NullTracer(),
        public Meter $meter = new NullMeter(),
    ) {}

    public function uniqueId(): string
    {
        return ($this->uniqueId)();
    }
}
