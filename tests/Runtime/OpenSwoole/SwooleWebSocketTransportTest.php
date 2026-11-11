<?php

namespace SurrealDB\Tests\Runtime\OpenSwoole;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SurrealDB\Codec\Codec;
use SurrealDB\Connection\DriverContext;
use SurrealDB\Connection\DriverOptions;
use SurrealDB\Connection\Endpoint;
use SurrealDB\Enum\CodecEnum;
use SurrealDB\Events\EventDispatcher;
use SurrealDB\Exceptions\SurrealException;
use SurrealDB\Scheduler\Swoole\SwooleScheduler;
use SurrealDB\Transport\Swoole\SwooleWebSocketTransport;
use SurrealDB\Tests\Fakes\StuckWebSocketServer;

final class SwooleWebSocketTransportTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('The openswoole extension is not installed.');
        }
    }

    public function testAServerCloseFrameEndsTheStream(): void
    {
        $server = new StuckWebSocketServer('fwrite($conn, chr(0x88) . chr(2) . pack("n", 1000));');
        $frames = [];

        try {
            \OpenSwoole\Coroutine::run(static function () use ($server, &$frames): void {
                $transport = new SwooleWebSocketTransport(self::context(), Endpoint::parse($server->url()));
                $transport->open();

                // It used to be read as an empty message, which then failed
                // to decode as CBOR.
                $frames[] = $transport->receiveFrame(2.0);
                $frames[] = $transport->isConnected();
            });
        } finally {
            $server->stop();
        }

        self::assertSame([null, false], $frames);
    }

    private static function context(): DriverContext
    {
        return new DriverContext(
            options: new DriverOptions(),
            codec: Codec::cbor(),
            format: CodecEnum::CBOR,
            events: new EventDispatcher(),
            logger: new NullLogger(),
            scheduler: new SwooleScheduler(),
            uniqueId: static fn (): string => '1',
        );
    }

    public function testAWriteTheServerStopsReadingEndsInsteadOfHanging(): void
    {
        $server = new StuckWebSocketServer();
        $outcome = null;

        try {
            \OpenSwoole\Coroutine::run(static function () use ($server, &$outcome): void {
                $transport = new SwooleWebSocketTransport(new DriverContext(
                    options: new DriverOptions(),
                    codec: Codec::cbor(),
                    format: CodecEnum::CBOR,
                    events: new EventDispatcher(),
                    logger: new NullLogger(),
                    scheduler: new SwooleScheduler(),
                    uniqueId: static fn (): string => '1',
                ), Endpoint::parse($server->url()), writeTimeout: 0.5);
                $transport->open();
                $started = microtime(true);

                try {
                    // Far more than the socket buffers hold.
                    $transport->sendFrame(str_repeat('x', 16 * 1024 * 1024));
                    $outcome = 'sent';
                } catch (SurrealException $error) {
                    $outcome = [$error->getMessage(), microtime(true) - $started, $transport->isConnected()];
                }
            });
        } finally {
            $server->stop();
        }

        self::assertIsArray($outcome, 'The write should have run out of time.');
        self::assertStringContainsString('stopped reading', $outcome[0]);
        self::assertLessThan(5.0, $outcome[1]);
        self::assertFalse($outcome[2]);
    }
}
