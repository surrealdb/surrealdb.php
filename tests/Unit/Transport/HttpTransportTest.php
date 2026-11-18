<?php

namespace SurrealDB\Tests\Unit\Transport;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use SurrealDB\Codec\Codec;
use SurrealDB\Connection\ConnectionState;
use SurrealDB\Connection\DriverContext;
use SurrealDB\Connection\DriverOptions;
use SurrealDB\Connection\Endpoint;
use SurrealDB\Connection\SessionState;
use SurrealDB\Enum\CodecEnum;
use SurrealDB\Events\EventDispatcher;
use SurrealDB\Reconnect\ExponentialBackoffReconnect;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Scheduler\SyncScheduler;
use SurrealDB\Transport\HttpTransport;

final class HttpTransportTest extends TestCase
{
    public function testCborHttpTransportUsesCborHeadersAndBody(): void
    {
        $codec = Codec::cbor();
        $captured = new \stdClass();
        $captured->request = null;
        $client = new class($codec, $captured) implements ClientInterface {
            public function __construct(
                private readonly Codec $codec,
                private readonly \stdClass $captured,
            ) {}

            public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->captured->request = $request;
                $decoded = $this->codec->deserialize((string) $request->getBody());

                TestCase::assertSame('version', $decoded['method'] ?? null);
                TestCase::assertSame('1', $decoded['id'] ?? null);

                return new Response(200, [], $this->codec->serialize(['id' => '1', 'result' => 'surrealdb-2.1.0']));
            }
        };
        $factory = new HttpFactory();
        $context = new DriverContext(
            options: new DriverOptions(
                format: CodecEnum::CBOR,
                httpClient: $client,
                requestFactory: $factory,
                streamFactory: $factory,
            ),
            codec: $codec,
            format: CodecEnum::CBOR,
            events: new EventDispatcher(),
            logger: new NullLogger(),
            scheduler: new SyncScheduler(),
            uniqueId: static fn (): string => '1',
        );
        $state = new ConnectionState(
            Endpoint::parse('http://localhost:8000/rpc'),
            new ExponentialBackoffReconnect(enabled: false),
            new SessionState(namespace: 'test', database: 'test'),
        );
        $transport = new HttpTransport($context, $state);
        $transport->open();

        $response = $transport->send(new RpcRequest('version', id: '1'));

        $this->assertSame('surrealdb-2.1.0', $response->result);
        $this->assertInstanceOf(RequestInterface::class, $captured->request);
        $this->assertSame('application/cbor', $captured->request->getHeaderLine('Content-Type'));
        $this->assertSame('application/cbor', $captured->request->getHeaderLine('Accept'));
        $this->assertSame('test', $captured->request->getHeaderLine('Surreal-NS'));
        $this->assertSame('test', $captured->request->getHeaderLine('Surreal-DB'));
    }
}
