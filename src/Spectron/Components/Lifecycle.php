<?php

namespace SurrealDB\Spectron\Components;

use SurrealDB\Spectron\Paths;
use SurrealDB\Spectron\Transport;

/** Operator lifecycle sweeps (expiry and decay). */
final class Lifecycle
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /**
     * Runs the context-category expiry sweep. Returns the number of affected rows.
     *
     * @return array<int|string,mixed>
     */
    public function expire(): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/expire', body: []) ?? [];
    }

    /**
     * Runs the importance decay sweep. Returns the number of affected rows.
     *
     * @return array<int|string,mixed>
     */
    public function decay(): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/decay', body: []) ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/lifecycle';
    }
}
