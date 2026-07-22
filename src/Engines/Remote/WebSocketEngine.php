<?php

namespace SurrealDB\SDK\Engines\Remote;

use SurrealDB\SDK\Connection\ConnectionState;
use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Contracts\DuplexTransportInterface;
use SurrealDB\SDK\Engines\AbstractRpcEngine;
use SurrealDB\SDK\Events\LiveMessageReceived;
use SurrealDB\SDK\Exceptions\CallTerminatedException;
use SurrealDB\SDK\Exceptions\ConnectionUnavailableException;
use SurrealDB\SDK\Live\LiveAction;
use SurrealDB\SDK\Live\LiveMessage;
use SurrealDB\SDK\Protocol\FeatureSet;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;
use SurrealDB\SDK\Transport\StreamWebSocketClient;
use SurrealDB\SDK\Transport\WebSocketTransport;
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
final class WebSocketEngine extends AbstractRpcEngine
{
    private ?DuplexTransportInterface $transport = null;
    private bool $active = false;
    private bool $terminated = false;

    /** @var array<string,Deferred<RpcResponse<mixed>>> */
    private array $calls = [];

    /** @var array<string,array<int,\Closure(LiveMessage<mixed>): void>> */
    private array $liveSubscribers = [];

    private int $liveSubscriberId = 0;

    public function features(): FeatureSet
    {
        return new FeatureSet(
            Features::liveQueries(),
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
        $this->connectOnce();

        // Background read + reconnect loop and keepalive pinger. Both are
        // no-ops under the synchronous scheduler (reads are driven on demand).
        $this->context->scheduler->spawn(fn () => $this->runLoop());
        $this->context->scheduler->spawn(fn () => $this->runPinger());
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
    }

    public function ready(): void
    {
        // Calls are dispatched immediately once active; nothing to flush.
    }

    public function liveQuery(string $id): iterable
    {
        /** @var list<LiveMessage<mixed>> $queue */
        $queue = [];
        /** @var Deferred<bool>|null $waiter */
        $waiter = null;

        $unsubscribe = $this->subscribeLive($id, function (LiveMessage $message) use (&$queue, &$waiter): void {
            $queue[] = $message;

            if ($waiter instanceof Deferred && $waiter->isPending()) {
                $pending = $waiter;
                $waiter = null;
                $pending->resolve(true);
            }
        });

        try {
            while (!$this->terminated) {
                while ($queue !== []) {
                    yield array_shift($queue);
                }

                if (!$this->active) {
                    break;
                }

                /** @var Deferred<bool> $waiter */
                $waiter = $this->context->scheduler->defer(fn () => $this->readAndDispatchOnce());

                try {
                    $waiter->await();
                } catch (\Throwable) {
                    break;
                }
            }

            while ($queue !== []) {
                yield array_shift($queue);
            }
        } finally {
            $unsubscribe();
        }
    }

    /**
     * @return RpcResponse<mixed>
     */
    protected function send(RpcRequest $request): RpcResponse
    {
        if (!$this->active || $this->transport === null) {
            throw new ConnectionUnavailableException();
        }

        $id = (string) $request->id;
        /** @var Deferred<RpcResponse<mixed>> $deferred */
        $deferred = $this->context->scheduler->defer(fn () => $this->readAndDispatchOnce());
        $this->calls[$id] = $deferred;

        $this->transport->sendFrame($this->context->codec->serialize($request->toWire()));

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

    private function connectOnce(): void
    {
        try {
            $this->transport?->open();
            $this->active = true;
            $this->state?->reconnect->reset();
            $this->publisher->publish('connected');
        } catch (\Throwable $error) {
            $this->active = false;
            $this->publisher->publish('error', $error);
        }
    }

    private function runLoop(): void
    {
        while (!$this->terminated) {
            if (!$this->active) {
                $this->connectOnce();
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

            $this->publisher->publish('reconnecting');
            $this->context->scheduler->delay($reconnect->nextDelay());

            $this->transport = $this->createTransport($this->state);
        }

        if (!$this->terminated) {
            $this->active = false;
            $this->failAllCalls(new CallTerminatedException());
            $this->publisher->publish('disconnected');
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
            $this->active = false;
            $this->failAllCalls(new CallTerminatedException());

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

                foreach ($this->liveSubscribers[$message->queryId] ?? [] as $listener) {
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

        return function () use ($id, $subscriberId): void {
            unset($this->liveSubscribers[$id][$subscriberId]);

            if (($this->liveSubscribers[$id] ?? null) === []) {
                unset($this->liveSubscribers[$id]);
            }
        };
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
