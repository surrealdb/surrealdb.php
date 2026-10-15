<?php

namespace SurrealDB\Telemetry\Psr;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use SurrealDB\Contracts\Counter;
use SurrealDB\Contracts\Histogram;
use SurrealDB\Contracts\Meter;

/**
 * A zero-dependency {@see Meter} that records measurements as structured PSR-3
 * log lines.
 */
final class Psr3Meter implements Meter
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $level = LogLevel::DEBUG,
    ) {}

    public function counter(string $name, ?string $unit = null, ?string $description = null): Counter
    {
        return new Psr3Counter($this->logger, $this->level, $name, $unit);
    }

    public function histogram(string $name, ?string $unit = null, ?string $description = null): Histogram
    {
        return new Psr3Histogram($this->logger, $this->level, $name, $unit);
    }
}
