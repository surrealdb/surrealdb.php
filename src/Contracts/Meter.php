<?php

namespace SurrealDB\SDK\Contracts;

/**
 * The vendor-neutral metrics seam. Creates instruments ({@see Counter},
 * {@see Histogram}) that record measurements to the configured backend.
 *
 * Kept separate from {@see Tracer} (interface segregation): a metrics-only
 * backend need not implement tracing, and vice versa. The default is a no-op
 * ({@see \SurrealDB\SDK\Telemetry\NullMeter}).
 */
interface Meter
{
    public function counter(string $name, ?string $unit = null, ?string $description = null): Counter;

    public function histogram(string $name, ?string $unit = null, ?string $description = null): Histogram;
}
