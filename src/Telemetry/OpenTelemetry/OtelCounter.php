<?php

namespace SurrealDB\Telemetry\OpenTelemetry;

use OpenTelemetry\API\Metrics\CounterInterface;
use SurrealDB\Contracts\Counter;

/** Wraps an OpenTelemetry counter instrument. */
final class OtelCounter implements Counter
{
    public function __construct(private readonly CounterInterface $counter) {}

    public function add(int|float $value = 1, array $attributes = []): void
    {
        $this->counter->add($value, $attributes);
    }
}
