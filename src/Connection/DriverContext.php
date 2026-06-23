<?php

namespace SurrealDB\SDK\Connection;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SurrealDB\SDK\Codec\Codec;
use SurrealDB\SDK\Contracts\Scheduler;
use SurrealDB\SDK\Enum\CodecEnum;

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
    ) {}

    public function uniqueId(): string
    {
        return ($this->uniqueId)();
    }
}
