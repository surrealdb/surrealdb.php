<?php

namespace SurrealDB\Connection;

use SurrealDB\Contracts\ReconnectStrategyInterface;

/**
 * The live state of a connection: endpoint, reconnect strategy, the root
 * session, and any additional named sessions.
 */
final class ConnectionState
{
    /** @var array<string,SessionState> */
    public array $sessions = [];

    public function __construct(
        public Endpoint $endpoint,
        public ReconnectStrategyInterface $reconnect,
        public SessionState $rootSession,
    ) {}

    /** @return list<SessionState> the root session followed by named sessions */
    public function allSessions(): array
    {
        return [$this->rootSession, ...array_values($this->sessions)];
    }

    public function session(?string $id): ?SessionState
    {
        if ($id === null || $id === $this->rootSession->id) {
            return $this->rootSession;
        }

        return $this->sessions[$id] ?? null;
    }
}
