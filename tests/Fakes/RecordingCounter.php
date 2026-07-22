<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\SDK\Contracts\Counter;

/** A {@see Counter} that records every increment for assertions. */
final class RecordingCounter implements Counter
{
    /** @var list<array{value: int|float, attributes: array<string, scalar|null>}> */
    public array $measurements = [];

    public function __construct(
        public readonly string $name,
        public readonly ?string $unit = null,
    ) {}

    public function add(int|float $value = 1, array $attributes = []): void
    {
        $this->measurements[] = ['value' => $value, 'attributes' => $attributes];
    }
}
