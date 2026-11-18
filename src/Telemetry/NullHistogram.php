<?php

namespace SurrealDB\Telemetry;

use SurrealDB\Contracts\Histogram;

/** A histogram that discards every measurement; returned by {@see NullMeter}. */
final class NullHistogram implements Histogram
{
    public function record(int|float $value, array $attributes = []): void {}
}
