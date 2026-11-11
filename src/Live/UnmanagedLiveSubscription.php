<?php

namespace SurrealDB\SDK\Live;

use SurrealDB\SDK\Connection\ConnectionController;
use SurrealDB\SDK\Types\Uuid;

/**
 * A subscription to a live query started elsewhere (for example by a `LIVE
 * SELECT` in a multi-statement query), attached by its id. It is not
 * re-issued after a reconnect.
 */
final class UnmanagedLiveSubscription extends LiveSubscription
{
    private readonly string $id;

    public function __construct(ConnectionController $connection, Uuid|string $id, ?string $session = null)
    {
        parent::__construct($connection, $session);
        $this->id = (string) Uuid::from($id);
        $this->feed = $connection->liveQuery($this->id);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isManaged(): bool
    {
        return false;
    }
}
