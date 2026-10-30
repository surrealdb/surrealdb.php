<?php

namespace SurrealDB\SDK\Live;

use SurrealDB\SDK\Connection\ConnectionController;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Query\BoundQuery;
use SurrealDB\SDK\Types\Uuid;

/**
 * A subscription to a live query's notifications. Iterate it to receive
 * {@see LiveMessage}s; iteration ends after {@see kill()}, after a server-side
 * `KILLED` notification, or when the connection closes.
 *
 * @implements \IteratorAggregate<int, LiveMessage<mixed>>
 */
abstract class LiveSubscription implements \IteratorAggregate
{
    /** @var iterable<LiveMessage<mixed>>|null */
    protected ?iterable $feed = null;

    protected bool $killed = false;

    protected bool $serverKilled = false;

    /** No more notifications are delivered: the session was reset or its principal changed. */
    protected bool $ended = false;

    public function __construct(
        protected readonly ConnectionController $connection,
        protected readonly ?string $session = null,
    ) {
        $connection->registerLive($this, $session);
    }

    /** The live query id the server assigned. */
    abstract public function id(): string;

    /** Whether the SDK issued the LIVE query (and re-issues it after a reconnect). */
    abstract public function isManaged(): bool;

    /**
     * Whether notifications can still arrive: not killed by the client or the
     * server, the session not reset, and the session still exists.
     */
    public function isAlive(): bool
    {
        return !$this->killed
            && !$this->killedByServer()
            && !$this->ended
            && $this->connection->state()?->session($this->session) !== null;
    }

    /**
     * Stop the live query on the server and end iteration. If `KILL` fails
     * the subscription is left as it was, so the call can be retried.
     */
    public function kill(): void
    {
        if ($this->killed) {
            return;
        }

        if (!$this->killedByServer() && !$this->ended) {
            self::killOnServer($this->connection, $this->id(), $this->session);
        }

        $this->killed = true;
        $this->closeFeed();
    }

    /**
     * @return \Generator<int, LiveMessage<mixed>>
     */
    public function getIterator(): \Generator
    {
        if ($this->killed) {
            throw new SurrealException('The live subscription has been killed.');
        }

        foreach ($this->feed ?? [] as $message) {
            if ($message->action === LiveAction::Killed) {
                $this->serverKilled = true;
            }

            yield $message;
        }
    }

    /**
     * @internal the session was reset, signed out or signed in as someone
     * else, which ends its live queries
     */
    public function sessionEnded(): void
    {
        $this->ended = true;

        // Drop what was queued too: it was delivered to whoever the session
        // was signed in as before.
        if ($this->feed instanceof LiveFeed) {
            $this->feed->discard();
        }
    }

    /**
     * Run `KILL` for a live query id. The id is validated as a UUID and inlined,
     * because the server does not accept a parameter in `KILL`.
     */
    public static function killOnServer(ConnectionController $connection, Uuid|string $id, ?string $session = null): void
    {
        $uuid = Uuid::from($id);

        foreach ($connection->query(new BoundQuery('KILL ' . $uuid->escape()), $session) as $chunk) {
            $chunk->resultOrThrow();
        }
    }

    /**
     * Whether the server ended the live query. The feed records a `KILLED`
     * notification as soon as it arrives, before anything iterates it.
     */
    protected function killedByServer(): bool
    {
        if (!$this->serverKilled && $this->feed instanceof LiveFeed && $this->feed->isKilled()) {
            $this->serverKilled = true;
        }

        return $this->serverKilled;
    }

    protected function closeFeed(): void
    {
        if ($this->feed instanceof LiveFeed) {
            $this->feed->close();
        }
    }
}
