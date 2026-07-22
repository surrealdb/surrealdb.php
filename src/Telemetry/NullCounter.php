<?php

namespace SurrealDB\SDK\Telemetry;

use SurrealDB\SDK\Contracts\Counter;

/** A counter that discards every measurement; returned by {@see NullMeter}. */
final class NullCounter implements Counter
{
    public function add(int|float $value = 1, array $attributes = []): void {}
}
