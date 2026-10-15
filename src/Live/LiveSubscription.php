<?php

namespace SurrealDB\SDK\Live;

use SurrealDB\SDK\Connection\ConnectionController;
use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Contracts\Scheduler;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Query\BoundQuery;
use SurrealDB\SDK\Types\Uuid;

/**
 * A subscription to a live query's notifications. Iterate it to receive
 * {@see LiveMessage}s; iteration ends after {@see kill()}, after a server-side
 * `KILLED` notification, or when the connection closes. A managed
 * subscription survives reconnects: iteration waits while the connection is
 * re-established and its query re-issued, then carries on.
 *
 * @implements \IteratorAggregate<int, LiveMessage<mixed>>
 */
abstract class LiveSubscription implements \IteratorAggregate
{
    /** @var iterable<LiveMessage<mixed>>|null */
    protected ?iterable $feed = null;

    /**
     * Feeds of lost sockets whose queued messages have not been yielded yet:
     * iteration drains them before moving on to the current feed.
     *
     * @var list<LiveFeed>
     */
    protected array $backlog = [];

    /** The backlog feed being iterated, no longer in {@see self::$backlog}. */
    private ?LiveFeed $draining = null;

    protected bool $killed = false;

    protected bool $serverKilled = false;

    /** The connection closed for good, or the session was reset or its principal changed. */
    protected bool $ended = false;

    /** @var Deferred<bool>|null settled once a reconnect's replay is done */
    private ?Deferred $replayed = null;

    public function __construct(
        protected readonly ConnectionController $connection,
        protected readonly ?string $session = null,
    ) {
        $connection->registerLive($this, $session);
    }

    /** @internal the session the query runs in */
    public function session(): ?string
    {
        return $this->session;
    }

    /** The live query id the server assigned. */
    abstract public function id(): string;

    /** Whether the SDK issued the LIVE query (and re-issues it after a reconnect). */
    abstract public function isManaged(): bool;

    /**
     * Whether notifications can still arrive: not killed by the client or the
     * server, the connection not closed (for an unmanaged subscription, not
     * lost: a new socket does not carry its query), the session not reset,
     * and the session still exists.
     */
    public function isAlive(): bool
    {
        return !$this->killed
            && !$this->killedByServer()
            && !$this->ended
            && ($this->isManaged() || !$this->feedEnded())
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
            $id = $this->id();
            self::killOnServer($this->connection, $id, $this->session);

            // A reconnect may have replayed the query under a new id while
            // KILL was in flight: stop that one too. Once the flag below is
            // set, a replay still in flight stops its own query.
            while ($this->id() !== $id && $this->id() !== '') {
                $id = $this->id();
                self::killOnServer($this->connection, $id, $this->session);
            }
        }

        $this->killed = true;
        $this->closeFeed();
        $this->stopped();
        $this->resume();
    }

    /**
     * @return \Generator<int, LiveMessage<mixed>>
     */
    public function getIterator(): \Generator
    {
        if ($this->killed) {
            throw new SurrealException('The live subscription has been killed.');
        }

        while (true) {
            $feed = $this->backlog !== [] ? array_shift($this->backlog) : $this->feed;
            $this->draining = $feed instanceof LiveFeed && $feed !== $this->feed ? $feed : null;

            foreach ($feed ?? [] as $message) {
                if ($message->action === LiveAction::Killed) {
                    $this->serverKilled = true;
                    $this->stopped();
                }

                yield $message;
            }

            if ($this->killed || $this->serverKilled) {
                return;
            }

            if ($this->backlog !== []) {
                continue;
            }

            if ($this->ended) {
                return;
            }

            // The feed ended with its socket: wait for a reconnect to replay
            // the query (a no-op if it already has).
            $replayed = $this->replayed;

            if ($replayed !== null && $replayed->isPending()) {
                try {
                    $replayed->await();
                } catch (\Throwable) {
                    return;
                }
            }

            if ($this->finished() || ($this->feed === $feed && $this->backlog === [])) {
                return;
            }
        }
    }

    /**
     * @internal the connection is reconnecting; iteration waits for
     * {@see self::resume()} rather than ending with the lost socket's feed
     */
    public function suspend(Scheduler $scheduler): void
    {
        if ($this->replayed === null || !$this->replayed->isPending()) {
            /** @var Deferred<bool> $replayed */
            $replayed = $scheduler->defer();
            $this->replayed = $replayed;
        }
    }

    /**
     * @internal the reconnect replayed the query: iteration carries on with
     * the new feed
     */
    public function resumeIteration(): void
    {
        $this->resume();
    }

    /**
     * @internal the connection closed for good, or the subscription cannot
     * be carried over to a new one
     */
    public function connectionClosed(): void
    {
        $this->ended = true;
        $this->endFeed();

        // Behind feeds of earlier sockets (including one being drained), as a
        // replay would put it, so it is still drained once they are.
        if (($this->backlog !== [] || $this->draining !== null) && $this->feed instanceof LiveFeed) {
            $this->backlog[] = $this->feed;
            $this->feed = null;
        }
        $this->stopped();
        $this->resume();
    }

    /**
     * @internal the session was reset, signed out or signed in as someone
     * else, which ends its live queries
     */
    public function sessionEnded(): void
    {
        $this->ended = true;

        // Drop what was queued too, the backlog included: it was delivered
        // to whoever the session was signed in as before.
        $this->closeFeed();
        $this->stopped();
        $this->resume();
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
     * Whether iteration is over for good. A method, not inline checks, since
     * the flags change while the generator is suspended.
     *
     * @phpstan-impure
     */
    private function finished(): bool
    {
        return $this->killed || $this->killedByServer() || $this->ended;
    }

    protected function resume(): void
    {
        // Cleared first: resolving can run the waiting consumer straight away
        // (OpenSwoole), and a reconnect it causes suspends iteration afresh.
        $replayed = $this->replayed;
        $this->replayed = null;

        if ($replayed?->isPending() === true) {
            $replayed->resolve(true);
        }
    }

    /**
     * Called once the live query has stopped on the server.
     */
    protected function stopped(): void {}

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

    /**
     * Whether the current feed stopped receiving (its socket was lost).
     */
    protected function feedEnded(): bool
    {
        return $this->feed instanceof LiveFeed && !$this->feed->isReceiving();
    }

    /**
     * Stop receiving and drop anything queued: after {@see kill()}, nothing
     * more is yielded.
     */
    protected function closeFeed(): void
    {
        foreach ($this->backlog as $feed) {
            $feed->close();
        }

        $this->backlog = [];
        $this->draining?->close();
        $this->draining = null;

        if ($this->feed instanceof LiveFeed) {
            $this->feed->close();
        }
    }

    /**
     * Stop receiving, but still yield what was already received.
     */
    protected function endFeed(): void
    {
        if ($this->feed instanceof LiveFeed) {
            $this->feed->end();
        }
    }
}
