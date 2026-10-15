<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\Contracts\Counter;
use SurrealDB\Contracts\Histogram;
use SurrealDB\Contracts\Meter;

/** A {@see Meter} that captures created instruments for assertions. */
final class RecordingMeter implements Meter
{
    /** @var array<string, RecordingCounter> */
    public array $counters = [];

    /** @var array<string, RecordingHistogram> */
    public array $histograms = [];

    public function counter(string $name, ?string $unit = null, ?string $description = null): Counter
    {
        return $this->counters[$name] ??= new RecordingCounter($name, $unit);
    }

    public function histogram(string $name, ?string $unit = null, ?string $description = null): Histogram
    {
        return $this->histograms[$name] ??= new RecordingHistogram($name, $unit);
    }
}
