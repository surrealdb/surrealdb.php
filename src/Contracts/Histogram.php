<?php

namespace SurrealDB\Contracts;

/**
 * A histogram instrument: each {@see record()} adds a sampled value (for
 * example an operation duration), optionally dimensioned by attributes.
 */
interface Histogram
{
    /**
     * @param array<non-empty-string, scalar|null> $attributes
     */
    public function record(int|float $value, array $attributes = []): void;
}
