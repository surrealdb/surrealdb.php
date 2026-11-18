<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory;

/** Options for {@see AgentMemory::reflect()}. */
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
