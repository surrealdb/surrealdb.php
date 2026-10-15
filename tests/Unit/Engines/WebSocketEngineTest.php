<?php

namespace SurrealDB\Tests\Unit\Engines;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SurrealDB\SDK\Codec\Codec;
use SurrealDB\SDK\Connection\ConnectionState;
use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Connection\Endpoint;
use SurrealDB\SDK\Connection\SessionState;
use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Contracts\DuplexTransportInterface;
use SurrealDB\SDK\Contracts\Scheduler;
use SurrealDB\SDK\Engines\Remote\WebSocketEngine;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Events\EventDispatcher;
use SurrealDB\SDK\Reconnect\ExponentialBackoffReconnect;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;
use SurrealDB\SDK\Scheduler\SyncDeferred;

final class WebSocketEngineTest extends TestCase
{
    public function testStaleReconnectAnnouncementIsDiscarded(): void
    {
        // Spawned tasks are queued and run only when the test drains them, so
        // the read loop can drop a fresh socket before its announcement runs.
        $scheduler = new class () implements Scheduler {
            /** @var list<\Closure> */
            public array $tasks = [];

            public function spawn(\Closure $task): void
            {
                $this->tasks[] = $task;
            }

            public function delay(float $seconds): void {}

            public function defer(?\Closure $drive = null): Deferred
            {
                return new SyncDeferred($drive);
            }
        };

        // 1: the initial socket, which drops. 2: a reconnect that drops again
        // before its announcement runs. 3: a reconnect that stays open; the
        // read loop "suspends" on it by throwing.
        $receivers = [
            static fn (): ?string => null,
            static fn (): ?string => null,
            static fn (): ?string => throw new \LogicException('suspended'),
        ];
        $options = new DriverOptions(
            format: CodecEnum::JSON,
            scheduler: $scheduler,
            webSocketTransportFactory: static function () use (&$receivers): DuplexTransportInterface {
                return self::transport(array_shift($receivers) ?? throw new \LogicException('unexpected transport'));
            },
            pingInterval: 0,
        );

        $engine = new WebSocketEngine(new DriverContext(
            options: $options,
            codec: Codec::json(),
            format: CodecEnum::JSON,
            events: new EventDispatcher(),
            logger: new NullLogger(),
            scheduler: $scheduler,
            uniqueId: static fn (): string => '1',
        ));

        $events = [];
        foreach (['connected', 'reconnecting', 'disconnected'] as $event) {
            $engine->subscribe($event, static function () use (&$events, $event): void {
                $events[] = $event;
            });
        }

        $engine->open(new ConnectionState(
            Endpoint::parse('ws://localhost:8000/rpc'),
            new ExponentialBackoffReconnect(retryDelay: 0),
            new SessionState(),
        ));

        while ($scheduler->tasks !== []) {
            try {
                (array_shift($scheduler->tasks))();
            } catch (\LogicException $error) {
                $this->assertSame('suspended', $error->getMessage());
            }
        }

        $this->assertSame(['connected', 'reconnecting', 'reconnecting', 'connected'], $events);
    }

    public function testInitialAnnouncementIsDiscardedIfAnEagerLoopLostTheSocket(): void
    {
        // Runs each spawned task straight away, as OpenSwoole does, until it
        // "suspends" (throws).
        $scheduler = new class () implements Scheduler {
            public function spawn(\Closure $task): void
            {
                try {
                    $task();
                } catch (\LogicException) {
                }
            }

            public function delay(float $seconds): void {}

            public function defer(?\Closure $drive = null): Deferred
            {
                return new SyncDeferred($drive);
            }
        };

        // 1: the initial socket, which the loop finds closed at once. 2: its
        // replacement, which stays open.
        $receivers = [
            static fn (): ?string => null,
            static fn (): ?string => throw new \LogicException('suspended'),
        ];
        $options = new DriverOptions(
            format: CodecEnum::JSON,
            scheduler: $scheduler,
            webSocketTransportFactory: static function () use (&$receivers): DuplexTransportInterface {
                return self::transport(array_shift($receivers) ?? throw new \LogicException('unexpected transport'));
            },
            pingInterval: 0,
        );

        $engine = new WebSocketEngine(new DriverContext(
            options: $options,
            codec: Codec::json(),
            format: CodecEnum::JSON,
            events: new EventDispatcher(),
            logger: new NullLogger(),
            scheduler: $scheduler,
            uniqueId: static fn (): string => '1',
        ));

        $events = [];
        foreach (['connected', 'reconnecting', 'disconnected'] as $event) {
            $engine->subscribe($event, static function () use (&$events, $event): void {
                $events[] = $event;
            });
        }

        $engine->open(new ConnectionState(
            Endpoint::parse('ws://localhost:8000/rpc'),
            new ExponentialBackoffReconnect(retryDelay: 0),
            new SessionState(),
        ));

        $this->assertSame(['reconnecting', 'connected'], $events);
    }

    /**
     * @param \Closure(): ?string $receive
     */
    private static function transport(\Closure $receive): DuplexTransportInterface
    {
        return new class ($receive) implements DuplexTransportInterface {
            public function __construct(private readonly \Closure $receive) {}

            public function open(): void {}

            public function close(): void {}

            public function isConnected(): bool
            {
                return true;
            }

            public function send(RpcRequest $request): RpcResponse
            {
                throw new \LogicException('not used');
            }

            public function sendFrame(string $payload): void {}

            public function receiveFrame(?float $timeout = null): ?string
            {
                return ($this->receive)();
            }
        };
    }
}
