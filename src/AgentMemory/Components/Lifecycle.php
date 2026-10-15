<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\LifecycleInterface;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Implements {@see LifecycleInterface} over the Agent Memory HTTP API. */
final class Lifecycle implements LifecycleInterface
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    public function expire(): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/expire', body: []) ?? [];
    }

    public function decay(): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/decay', body: []) ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/lifecycle';
    }
}
