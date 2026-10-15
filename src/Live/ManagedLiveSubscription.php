<?php

namespace SurrealDB\SDK\Live;

use SurrealDB\SDK\Connection\ConnectionController;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Query\BoundQuery;

/**
 * A live subscription whose `LIVE SELECT` the SDK issued. Created by
 * {@see \SurrealDB\SDK\Query\LiveSelectQuery::execute()}; the query is already
 * registered on the server, and this client subscribed, when it is returned.
 */
final class ManagedLiveSubscription extends LiveSubscription
{
    private string $id = '';

    public function __construct(
        ConnectionController $connection,
        private readonly BoundQuery $query,
        ?string $session = null,
    ) {
        parent::__construct($connection, $session);
    }

    /**
     * @internal issue the LIVE query and start receiving its notifications
     */
    public function start(): void
    {
        $result = null;

        foreach ($this->connection->query($this->query, $this->session) as $chunk) {
            $result = $chunk->resultOrThrow();
        }

        if ($result === null) {
            throw new SurrealException('The LIVE query did not return a live query id.');
        }

        // Killed while the query was in flight (another task, during a
        // replay): don't start receiving, and stop the query just issued.
        if ($this->killed) {
            try {
                self::killOnServer($this->connection, (string) $result, $this->session);
            } catch (\Throwable) {
                // The caller already considers it stopped.
            }

            return;
        }

        $this->id = (string) $result;
        $this->feed = $this->connection->liveQuery($this->id);
        $this->connection->trackLive($this);
    }

    /**
     * @internal re-issue the LIVE query on a new connection. The server
     * assigns a new id, which {@see self::id()} then returns.
     */
    public function replay(): void
    {
        // Keep what the lost socket already delivered: iteration yields it
        // before the replayed query's notifications.
        if ($this->feed instanceof LiveFeed) {
            $this->feed->end();
            $this->backlog[] = $this->feed;
            $this->feed = null;
        }

        // On failure the connection decides: wait for the next socket, or
        // end the subscription. On success it resumes iteration, once every
        // subscription is replayed.
        $this->start();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isManaged(): bool
    {
        return true;
    }

    protected function stopped(): void
    {
        $this->connection->untrackLive($this);
    }

    /** The `LIVE SELECT` statement this subscription runs. */
    public function query(): BoundQuery
    {
        return $this->query;
    }
}
