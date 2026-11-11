<?php

namespace SurrealDB\Tests\Runtime\FrankenPHP;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use SurrealDB\SDK\Connection\ConnectionStatus;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Scheduler\Amp\RevoltScheduler;
use SurrealDB\SDK\Surreal;
use SurrealDB\Tests\Fakes\SlowOpeningTransport;

final class RevoltCloseDuringReconnectTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(EventLoop::class)) {
            $this->markTestSkipped('revolt/event-loop is not installed.');
        }
    }

    public function testClosingWhileAReconnectOpensASocketClosesThatSocket(): void
    {
        /** @var list<SlowOpeningTransport> $sockets */
        $sockets = [];
        $options = new DriverOptions(
            scheduler: new RevoltScheduler(),
            webSocketTransportFactory: static function () use (&$sockets): SlowOpeningTransport {
                // The first opens at once, the reconnect's takes a while.
                return $sockets[] = new SlowOpeningTransport($sockets === [] ? 0.0 : 0.2);
            },
            pingInterval: 0,
        );

        $db = new Surreal($options);
        $db->connect('ws://localhost:8000/rpc', new ConnectOptions(
            namespace: 'test',
            database: 'test',
            reconnect: ['retryDelay' => 0.001, 'retryDelayMax' => 0.001],
        ));

        $sockets[0]->drop();
        self::wait(0.05);
        self::assertCount(2, $sockets, 'The reconnect should be opening a second socket.');

        $db->close();
        self::wait(0.4);

        // It used to finish opening after close(), and stay open for good.
        self::assertFalse($sockets[1]->connected);
        self::assertSame(ConnectionStatus::Disconnected, $db->status());
    }

    private static function wait(float $seconds): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay($seconds, static fn () => $suspension->resume());
        $suspension->suspend();
    }
}
