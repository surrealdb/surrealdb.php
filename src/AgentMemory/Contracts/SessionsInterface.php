<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Options\SessionCreateOptions;

/** Creates and manages conversation sessions for a context. */
interface SessionsInterface
{
    /** Opens a new session with an optional DNF scope selector and metadata. */
    public function create(?SessionCreateOptions $options = null): SessionInterface;

    /**
     * A handle on an existing session, such as the one {@see AgentMemoryInterface::remember()},
     * {@see AgentMemoryInterface::rememberMany()} or {@see AgentMemoryInterface::chat()} auto-created
     * and returned as `sessionId`. No request is made.
     */
    public function attach(string $sessionId): SessionInterface;
}
