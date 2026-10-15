<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\SessionCreateOptions;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Creates and manages conversation sessions for a context. */
final class Sessions
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /** Opens a new session with an optional DNF scope selector and metadata. */
    public function create(?SessionCreateOptions $options = null): Session
    {
        $info = $this->transport->requestJson(
            'POST',
            Paths::contextApiPrefix($this->contextId) . '/sessions',
            body: $options?->toPayload() ?? [],
        ) ?? [];

        return new Session($this->transport, $this->contextId, $info);
    }

    /**
     * A handle on an existing session, such as the one {@see AgentMemory::remember()},
     * {@see AgentMemory::rememberMany()} or {@see AgentMemory::chat()} auto-created
     * and returned as `sessionId`. No request is made.
     */
    public function attach(string $sessionId): Session
    {
        if (trim($sessionId) === '') {
            throw new InvalidArgumentException('Sessions::attach() requires a session id.');
        }

        return new Session($this->transport, $this->contextId, ['id' => $sessionId]);
    }
}
