<?php

namespace SurrealDB\Telemetry;

use SurrealDB\Contracts\Counter;
use SurrealDB\Contracts\Histogram;
use SurrealDB\Contracts\Meter;

/** The default no-op meter: metrics are off until one is configured. */
final class NullMeter implements Meter
{
    public function counter(string $name, ?string $unit = null, ?string $description = null): Counter
    {
        return new NullCounter();
    }

    public function histogram(string $name, ?string $unit = null, ?string $description = null): Histogram
    {
        return new NullHistogram();
    }
}
