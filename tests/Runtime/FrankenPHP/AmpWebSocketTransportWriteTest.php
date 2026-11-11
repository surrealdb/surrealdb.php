<?php

namespace SurrealDB\Tests\Runtime\FrankenPHP;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SurrealDB\SDK\Codec\Codec;
use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Connection\Endpoint;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Events\EventDispatcher;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Scheduler\Amp\RevoltScheduler;
use SurrealDB\SDK\Transport\Amp\AmpWebSocketTransport;
use SurrealDB\Tests\Fakes\StuckWebSocketServer;

final class AmpWebSocketTransportWriteTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Revolt\EventLoop::class)) {
            $this->markTestSkipped('revolt/event-loop is not installed.');
        }
    }

    public function testAWriteTheServerStopsReadingEndsInsteadOfHanging(): void
    {
        $server = new StuckWebSocketServer();
        $outcome = null;

        try {
            (static function () use ($server, &$outcome): void {
                $transport = new AmpWebSocketTransport(new DriverContext(
                    options: new DriverOptions(),
                    codec: Codec::cbor(),
                    format: CodecEnum::CBOR,
                    events: new EventDispatcher(),
                    logger: new NullLogger(),
                    scheduler: new RevoltScheduler(),
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
            })();
        } finally {
            $server->stop();
        }

        self::assertIsArray($outcome, 'The write should have run out of time.');
        self::assertStringContainsString('stopped reading', $outcome[0]);
        self::assertLessThan(8.0, $outcome[1]);
        self::assertFalse($outcome[2]);
    }
}
