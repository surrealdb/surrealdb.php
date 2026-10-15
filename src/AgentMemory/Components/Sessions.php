<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\SessionsInterface;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\SessionCreateOptions;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Implements {@see SessionsInterface} over the Agent Memory HTTP API. */
final class Sessions implements SessionsInterface
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    public function create(?SessionCreateOptions $options = null): Session
    {
        $info = $this->transport->requestJson(
            'POST',
            Paths::contextApiPrefix($this->contextId) . '/sessions',
            body: $options?->toPayload() ?? [],
        ) ?? [];

        return new Session($this->transport, $this->contextId, $info);
    }

    public function attach(string $sessionId): Session
    {
        Guard::notBlank($sessionId, 'Sessions::attach() requires a session id.');

        return new Session($this->transport, $this->contextId, ['id' => $sessionId]);
    }
}
