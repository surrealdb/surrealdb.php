<?php

namespace SurrealDB\SDK\Engines\Remote;

use SurrealDB\SDK\Connection\ConnectionState;
use SurrealDB\SDK\Contracts\ConnectionProbeInterface;
use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Contracts\DuplexTransportInterface;
use SurrealDB\SDK\Engines\AbstractRpcEngine;
use SurrealDB\SDK\Events\LiveMessageReceived;
use SurrealDB\SDK\Exceptions\CallTerminatedException;
use SurrealDB\SDK\Exceptions\ConnectionUnavailableException;
use SurrealDB\SDK\Live\LiveAction;
use SurrealDB\SDK\Live\LiveFeed;
use SurrealDB\SDK\Live\LiveMessage;
use SurrealDB\SDK\Protocol\FeatureSet;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;
use SurrealDB\SDK\Transport\StreamWebSocketClient;
use SurrealDB\SDK\Transport\WebSocketTransport;
use function count;
use function is_array;
use function is_string;

/**
 * An engine that maintains a persistent WebSocket connection.
 *
 * Written entirely against the {@see \SurrealDB\SDK\Contracts\Scheduler} seam:
 * each `send()` registers a {@see Deferred} keyed by request id and awaits it,
 * while a single read loop resolves response deferreds and routes live
 * messages. Under the synchronous scheduler the loop is driven on demand by the
 * awaiting deferred; under an async scheduler it runs as a background task.
 */
final class WebSocketEngine extends AbstractRpcEngine implements ConnectionProbeInterface
{
    private ?DuplexTransportInterface $transport = null;
    private bool $active = false;
    private bool $terminated = false;

    /**
     * Whether the background read loop is running. Under the synchronous
     * scheduler it never is, so the engine itself reports a lost connection.
     */
    private bool $loopRunning = false;

    /** Incremented on every successful open, identifying the active transport. */
    private int $generation = 0;

    /** @var array<string,Deferred<RpcResponse<mixed>>> */
    private array $calls = [];

    /** @var array<string,array<int,\Closure(LiveMessage<mixed>): void>> */
    private array $liveSubscribers = [];

    private int $liveSubscriberId = 0;

    /** Notifications kept for live queries nobody has subscribed to yet. */
    private const int MAX_UNCLAIMED_LIVE = 1000;

    /**
     * Keyed by query id, then by arrival sequence (global FIFO order).
     *
     * @var array<string,array<int,LiveMessage<mixed>>>
     */
    private array $unclaimedLive = [];

    private int $unclaimedCount = 0;

    /**
     * Live feeds bound to the current socket, held weakly so a subscription
     * dropped without kill() can still be destroyed. A new socket does not
     * carry the old one's live queries, so they end when it is lost.
     *
     * @var array<int,\WeakReference<LiveFeed>>
     */
    private array $feeds = [];

    private int $unclaimedSequence = 0;

    public function features(): FeatureSet
    {
        return new FeatureSet(
            Features::liveQueries(),
            Features::liveQueryBindings(),
            Features::refreshTokens(),
            Features::sessions(),
            Features::transactions(),
            Features::api(),
            Features::exportImportRaw(),
            Features::surrealMl(),
        );
    }

    public function open(ConnectionState $state): void
    {
        $this->state = $state;
        $this->terminated = false;
        $this->transport = $this->createTransport($state);

        // Connect once synchronously so the controller can proceed immediately.
        $connected = $this->connectOnce();
        $generation = $this->generation;

        // Background read + reconnect loop and keepalive pinger. Both are
        // no-ops under the synchronous scheduler (reads are driven on demand).
        // They must be spawned before `connected` is announced: the controller
        // answers it with RPCs (version, session restore) whose replies only
        // the read loop can deliver under an async scheduler.
        $this->context->scheduler->spawn(fn () => $this->runLoop());
        $this->context->scheduler->spawn(fn () => $this->runPinger());

        // An eager scheduler may already have run the read loop, which can
        // have lost this socket (and announced its replacement itself).
        if ($connected && $this->isCurrent($generation)) {
            $this->announceConnected($generation);
        }
    }

    /**
     * Announce a newly opened socket. The reconnect strategy starts over
     * only once the session is restored on it: a socket that keeps dropping
     * half way through still uses up the attempts.
     */
    private function announceConnected(int $generation): void
    {
        $this->publisher->publish('connected');

        if ($this->isCurrent($generation)) {
            $this->state?->reconnect->reset();
        }
    }

    /**
     * Whether the socket opened as `$generation` is still the live one.
     */
    private function isCurrent(int $generation): bool
    {
        return $this->active && !$this->terminated && $this->generation === $generation;
    }

    public function close(): void
    {
        if ($this->terminated) {
            return;
        }

        $this->terminated = true;
        $this->active = false;
        $this->transport?->close();
        $this->transport = null;
        $this->failAllCalls(new CallTerminatedException());
        $this->publisher->publish('disconnected');
        $this->closeFeeds();
    }

    public function ready(): void
    {
        // Calls are dispatched immediately once active; nothing to flush.
    }

    public function probe(): void
    {
        if ($this->active && !$this->loopRunning && $this->transport?->isConnected() !== true) {
            $this->connectionLost();
        }
    }

    /**
     * Start receiving the notifications for a live query. The feed is
     * registered immediately, and any notifications that arrived for this id
     * before anyone subscribed are replayed into it.
     */
    public function liveQuery(string $id): LiveFeed
    {
        $feed = new LiveFeed(
            $id,
            $this->context->scheduler,
            fn (): bool => $this->readAndDispatchOnce(),
            fn (): bool => $this->active && !$this->terminated,
        );

        // Hold the feed weakly so a subscription dropped without kill() is
        // destroyed, which closes the feed and unsubscribes it.
        $key = spl_object_id($feed);
        $weak = \WeakReference::create($feed);
        $unsubscribe = $this->subscribeLive($id, static function (LiveMessage $message) use ($weak): void {
            $weak->get()?->push($message);
        });
        $this->feeds[$key] = $weak;
        $feed->onClose(function () use ($unsubscribe, $key, $weak): void {
            $unsubscribe();

            if (($this->feeds[$key] ?? null) === $weak) {
                unset($this->feeds[$key]);
            }
        });

        return $feed;
    }

    /**
     * @return RpcResponse<mixed>
     */
    protected function send(RpcRequest $request): RpcResponse
    {
        if (!$this->active || $this->transport === null) {
            throw new ConnectionUnavailableException();
        }

        // A value the codec cannot encode is the caller's error, not a lost
        // connection, so serialize before the transport-failure handling.
        $payload = $this->context->codec->serialize($request->toWire());

        $id = (string) $request->id;
        /** @var Deferred<RpcResponse<mixed>> $deferred */
        $deferred = $this->context->scheduler->defer(fn () => $this->readAndDispatchOnce());
        $this->calls[$id] = $deferred;

        try {
            $this->transport->sendFrame($payload);
        } catch (\Throwable $error) {
            unset($this->calls[$id]);
            $this->connectionLost();

            throw $error;
        }

        /** @var RpcResponse<mixed> $response */
        $response = $deferred->await();

        return $response;
    }

    private function createTransport(ConnectionState $state): DuplexTransportInterface
    {
        $options = $this->context->options;

        if ($options->webSocketTransportFactory !== null) {
            return ($options->webSocketTransportFactory)($this->context, $state);
        }

        $client = $options->webSocketClientFactory !== null
            ? ($options->webSocketClientFactory)($state->endpoint, $this->context)
            : new StreamWebSocketClient();

        return new WebSocketTransport($this->context, $state->endpoint, $client);
    }

    /**
     * Open the transport. The caller announces `connected` once something is
     * able to read the replies to the RPCs that announcement triggers.
     *
     * @phpstan-impure
     */
    private function connectOnce(): bool
    {
        if ($this->transport === null) {
            return false;
        }

        try {
            $this->transport->open();
            $this->active = true;
            $this->generation++;

            return true;
        } catch (\Throwable $error) {
            $this->active = false;
            $this->publisher->publish('error', $error);

            return false;
        }
    }

    private function runLoop(): void
    {
        $this->loopRunning = true;

        try {
            $this->readLoop();
        } finally {
            $this->loopRunning = false;
        }
    }

    private function readLoop(): void
    {
        while (!$this->terminated) {
            // This loop is the only reader, so it cannot itself wait on the
            // RPCs that restore the session: announce from a separate task.
            if (!$this->active && $this->connectOnce()) {
                $generation = $this->generation;
                $this->context->scheduler->spawn(function () use ($generation): void {
                    // The socket may have dropped (and a retry begun) before
                    // this task ran; announcing then would answer a dead link.
                    if ($this->isCurrent($generation)) {
                        $this->announceConnected($generation);
                    }
                });
            }

            while ($this->active && !$this->terminated) {
                if (!$this->readAndDispatchOnce()) {
                    break;
                }
            }

            $reconnect = $this->state?->reconnect;

            if ($this->terminated || $reconnect === null || !$reconnect->enabled() || !$reconnect->allowed()) {
                break;
            }

            $this->announceReconnecting();
            $this->context->scheduler->delay($reconnect->nextDelay());

            // A listener or another task may have closed the engine meanwhile.
            if ($this->terminated) {
                break;
            }

            $this->replaceTransport();
        }

        if (!$this->terminated) {
            $this->active = false;
            $this->failAllCalls(new CallTerminatedException());
            $this->publisher->publish('disconnected');
            $this->closeFeeds();
        }
    }

    /**
     * Announce a reconnect attempt, then end the live feeds of the lost
     * socket. Announcing first lets managed live subscriptions know to wait
     * for their replay instead of ending with their feed.
     */
    private function announceReconnecting(): void
    {
        $this->publisher->publish('reconnecting');
        $this->closeFeeds();
    }

    /**
     * Reconnect without a background loop (synchronous scheduler): retry
     * with the reconnect strategy's backoff until a socket opens, then
     * announce `connected` so the controller restores the sessions and
     * replays managed live queries.
     *
     * @phpstan-impure
     */
    private function reconnectInline(): bool
    {
        $reconnect = $this->state?->reconnect;

        while (!$this->terminated && $this->state !== null && $reconnect !== null && $reconnect->enabled() && $reconnect->allowed()) {
            $this->announceReconnecting();
            $this->context->scheduler->delay($reconnect->nextDelay());

            // A `reconnecting` listener may have closed the engine.
            if ($this->terminated) {
                return false;
            }

            $this->transport?->close();
            $this->replaceTransport();

            if ($this->connectOnce()) {
                $this->announceConnected($this->generation);

                return true;
            }
        }

        return false;
    }

    /**
     * Create the next transport. A factory that throws counts as a failed
     * attempt, like a socket that does not open, rather than ending the
     * reconnect loop half way.
     */
    private function replaceTransport(): void
    {
        try {
            $this->transport = $this->state === null ? null : $this->createTransport($this->state);
        } catch (\Throwable $error) {
            $this->transport = null;
            $this->publisher->publish('error', $error);
        }
    }

    private function closeFeeds(): void
    {
        $feeds = $this->feeds;
        $this->feeds = [];
        $this->unclaimedLive = [];
        $this->unclaimedCount = 0;

        // Ended rather than closed: what they already received is still
        // yielded.
        foreach ($feeds as $weak) {
            $weak->get()?->end();
        }
    }

    private function runPinger(): void
    {
        $interval = (float) $this->context->options->pingInterval;

        if ($interval <= 0) {
            return;
        }

        while (!$this->terminated) {
            $this->context->scheduler->delay($interval);

            if ($this->active) {
                try {
                    $this->dispatch(new RpcRequest('ping'));
                } catch (\Throwable) {
                    // Keepalive failures surface through the read loop.
                }
            }
        }
    }

    private function readAndDispatchOnce(): bool
    {
        if ($this->transport === null) {
            return false;
        }

        $frame = $this->transport->receiveFrame();

        if ($frame === null) {
            $this->connectionLost();

            return false;
        }

        $decoded = $this->context->codec->deserialize($frame);

        if (is_array($decoded)) {
            $this->handleMessage($decoded);
        }

        return true;
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function handleMessage(array $decoded): void
    {
        $id = $decoded['id'] ?? null;

        if (is_string($id) && isset($this->calls[$id])) {
            $deferred = $this->calls[$id];
            unset($this->calls[$id]);
            $deferred->resolve(RpcResponse::fromWire($decoded));

            return;
        }

        $result = $decoded['result'] ?? null;

        if (is_array($result)) {
            $message = $this->parseLiveMessage($result);

            if ($message !== null) {
                $this->context->events->dispatch(new LiveMessageReceived($message));
                $listeners = $this->liveSubscribers[$message->queryId] ?? [];

                if ($listeners === []) {
                    $this->holdUnclaimed($message);
                }

                foreach ($listeners as $listener) {
                    $listener($message);
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $result
     *
     * @return LiveMessage<mixed>|null
     */
    private function parseLiveMessage(array $result): ?LiveMessage
    {
        if (!isset($result['id'], $result['action'])) {
            return null;
        }

        $action = LiveAction::tryFrom(strtoupper((string) $result['action']));

        if ($action === null) {
            return null;
        }

        return new LiveMessage((string) $result['id'], $action, $result['record'] ?? null, $result['result'] ?? null);
    }

    /**
     * @param \Closure(LiveMessage<mixed>): void $listener
     */
    private function subscribeLive(string $id, \Closure $listener): \Closure
    {
        $subscriberId = $this->liveSubscriberId++;
        $this->liveSubscribers[$id][$subscriberId] = $listener;

        $held = $this->unclaimedLive[$id] ?? [];
        unset($this->unclaimedLive[$id]);
        $this->unclaimedCount -= count($held);

        foreach ($held as $message) {
            $listener($message);
        }

        return function () use ($id, $subscriberId): void {
            unset($this->liveSubscribers[$id][$subscriberId]);

            if (($this->liveSubscribers[$id] ?? null) === []) {
                unset($this->liveSubscribers[$id]);
            }
        };
    }

    /**
     * The socket closed or failed. Pending calls fail; when a background loop
     * is running it owns reconnection. Otherwise (synchronous scheduler) the
     * engine reconnects here, and if it cannot, the connection is closed and
     * `disconnected` is published so the controller's status no longer
     * reports it as connected.
     */
    private function connectionLost(): void
    {
        $this->active = false;
        $this->failAllCalls(new CallTerminatedException());

        if ($this->loopRunning || $this->terminated) {
            return;
        }

        // Also done if a `reconnecting` listener closed the engine.
        if ($this->reconnectInline() || $this->terminated) {
            return;
        }

        $this->terminated = true;
        $this->transport?->close();
        $this->transport = null;
        $this->publisher->publish('disconnected');
        $this->closeFeeds();
    }

    /**
     * Keep a notification nobody has subscribed to yet: a LIVE query's first
     * notifications can arrive before its caller starts listening.
     *
     * @param LiveMessage<mixed> $message
     */
    private function holdUnclaimed(LiveMessage $message): void
    {
        $this->unclaimedLive[$message->queryId][$this->unclaimedSequence++] = $message;
        $this->unclaimedCount++;

        // Bound the buffer: drop the globally oldest message (live queries
        // nobody subscribes to, e.g. ones another client attached to this
        // session).
        while ($this->unclaimedCount > self::MAX_UNCLAIMED_LIVE) {
            $oldest = null;
            $oldestSequence = PHP_INT_MAX;

            foreach ($this->unclaimedLive as $queryId => $held) {
                $sequence = array_key_first($held);

                if ($sequence !== null && $sequence < $oldestSequence) {
                    $oldest = $queryId;
                    $oldestSequence = $sequence;
                }
            }

            if ($oldest === null) {
                break;
            }

            unset($this->unclaimedLive[$oldest][$oldestSequence]);
            $this->unclaimedCount--;

            if ($this->unclaimedLive[$oldest] === []) {
                unset($this->unclaimedLive[$oldest]);
            }
        }
    }

    private function failAllCalls(\Throwable $error): void
    {
        $calls = $this->calls;
        $this->calls = [];

        foreach ($calls as $deferred) {
            if ($deferred->isPending()) {
                $deferred->fail($error);
            }
        }
    }
}
