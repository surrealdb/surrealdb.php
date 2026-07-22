<?php

namespace SurrealDB\SDK\Telemetry;

use SurrealDB\SDK\Contracts\Counter;
use SurrealDB\SDK\Contracts\Histogram;
use SurrealDB\SDK\Contracts\Meter;

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
