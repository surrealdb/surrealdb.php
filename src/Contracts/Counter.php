<?php

namespace SurrealDB\SDK\Contracts;

/**
 * A monotonic counter instrument: each {@see add()} increments the total by a
 * non-negative amount, optionally dimensioned by attributes.
 */
interface Counter
{
    /**
     * @param array<non-empty-string, scalar|null> $attributes
     */
    public function add(int|float $value = 1, array $attributes = []): void;
}
