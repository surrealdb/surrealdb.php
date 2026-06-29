<?php

namespace SurrealDB\SDK\Telemetry\OpenTelemetry;

use OpenTelemetry\API\Metrics\HistogramInterface;
use SurrealDB\SDK\Contracts\Histogram;

/** Wraps an OpenTelemetry histogram instrument. */
final class OtelHistogram implements Histogram
{
    public function __construct(private readonly HistogramInterface $histogram) {}

    public function record(int|float $value, array $attributes = []): void
    {
        $this->histogram->record($value, $attributes);
    }
}
