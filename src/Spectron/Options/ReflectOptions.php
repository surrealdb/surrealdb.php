<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron;

/** Options for {@see Spectron::reflect()}. */
final readonly class ReflectOptions
{
    /**
     * @param bool $persist persist attributes the reflection derives
     */
    public function __construct(
        public bool $persist = false,
    ) {}

    /**
     * @internal wire fields for `POST /reflect`; `persist` is always sent
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return ['persist' => $this->persist];
    }
}
