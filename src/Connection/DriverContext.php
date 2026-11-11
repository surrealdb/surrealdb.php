<?php

namespace SurrealDB\Connection;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SurrealDB\Codec\Codec;
use SurrealDB\Contracts\Meter;
use SurrealDB\Contracts\Scheduler;
use SurrealDB\Contracts\Tracer;
use SurrealDB\Enum\CodecEnum;
use SurrealDB\Telemetry\NullMeter;
use SurrealDB\Telemetry\NullTracer;

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
