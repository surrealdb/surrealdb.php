<?php

namespace SurrealDB\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SurrealDB\Codec\Codec;
use SurrealDB\Connection\DriverContext;
use SurrealDB\Connection\DriverOptions;
use SurrealDB\Connection\Endpoint;
use SurrealDB\Enum\CodecEnum;
use SurrealDB\Events\EventDispatcher;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Scheduler\SyncScheduler;
use SurrealDB\Transport\WebSocketTransport;
use SurrealDB\Tests\Fakes\FakeWebSocketClient;

final class WebSocketTransportTest extends TestCase
{
    public function testCborNegotiatesCborSubprotocolAndSendsBinaryFrames(): void
    {
        $client = new FakeWebSocketClient();
        $transport = new WebSocketTransport(
            $this->context(CodecEnum::CBOR, Codec::cbor()),
            Endpoint::parse('ws://localhost:8000/rpc'),
            $client,
        );

        $transport->open();
        $transport->sendFrame(Codec::cbor()->serialize((new RpcRequest('version', id: '1'))->toWire()));

        $this->assertSame(['cbor'], $client->subprotocols);
        $this->assertTrue($client->sent[0]['binary']);
    }

    public function testJsonNegotiatesJsonSubprotocolAndSendsTextFrames(): void
    {
        $client = new FakeWebSocketClient();
        $transport = new WebSocketTransport(
            $this->context(CodecEnum::JSON, Codec::json()),
            Endpoint::parse('ws://localhost:8000/rpc'),
            $client,
        );

        $transport->open();
        $transport->sendFrame(Codec::json()->serialize((new RpcRequest('version', id: '1'))->toWire()));

        $this->assertSame(['json'], $client->subprotocols);
        $this->assertFalse($client->sent[0]['binary']);
    }

    private function context(CodecEnum $format, Codec $codec): DriverContext
    {
        return new DriverContext(
            options: new DriverOptions(format: $format),
            codec: $codec,
            format: $format,
            events: new EventDispatcher(),
            logger: new NullLogger(),
            scheduler: new SyncScheduler(),
            uniqueId: static fn (): string => '1',
        );
    }
}
