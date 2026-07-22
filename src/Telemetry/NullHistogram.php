<?php

namespace SurrealDB\SDK\Telemetry;

use SurrealDB\SDK\Contracts\Histogram;

/** A histogram that discards every measurement; returned by {@see NullMeter}. */
final class NullHistogram implements Histogram
{
    public function record(int|float $value, array $attributes = []): void {}
}
