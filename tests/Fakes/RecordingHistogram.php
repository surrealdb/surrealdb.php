<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\Contracts\Histogram;

/** A {@see Histogram} that records every measurement for assertions. */
final class RecordingHistogram implements Histogram
{
    /** @var list<array{value: int|float, attributes: array<string, scalar|null>}> */
    public array $measurements = [];

    public function __construct(
        public readonly string $name,
        public readonly ?string $unit = null,
    ) {}

    public function record(int|float $value, array $attributes = []): void
    {
        $this->measurements[] = ['value' => $value, 'attributes' => $attributes];
    }
}
