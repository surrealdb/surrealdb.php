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

        $this->id = (string) $result;
        $this->feed = $this->connection->liveQuery($this->id);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isManaged(): bool
    {
        return true;
    }

    /** The `LIVE SELECT` statement this subscription runs. */
    public function query(): BoundQuery
    {
        return $this->query;
    }
}
