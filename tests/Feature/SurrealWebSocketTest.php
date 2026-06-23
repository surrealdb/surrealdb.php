<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Events\Connected;
use SurrealDB\SDK\Surreal;
use SurrealDB\Tests\Fakes\FakeDuplexTransport;

final class SurrealWebSocketTest extends TestCase
{
    private function responder(): \Closure
    {
        return static fn (string $method, array $params, ?string $id): array => match ($method) {
            'version' => ['id' => $id, 'result' => 'surrealdb-2.1.0'],
            'signin' => ['id' => $id, 'result' => 'header.payload.signature'],
            'query' => ['id' => $id, 'result' => [
                ['status' => 'OK', 'time' => '1.2ms', 'result' => [['id' => 'person:tobie', 'name' => 'Tobie']]],
            ]],
            default => ['id' => $id, 'result' => null],
        };
    }

    private function connect(?FakeDuplexTransport &$transport = null): Surreal
    {
        $responder = $this->responder();
        $captured = null;

        $options = new DriverOptions(
            webSocketTransportFactory: function () use ($responder, &$captured): FakeDuplexTransport {
                return $captured = new FakeDuplexTransport($responder);
            },
        );

        $db = new Surreal($options);
        $db->connect('ws://localhost:8000/rpc', new ConnectOptions(namespace: 'test', database: 'test'));

        $transport = $captured;

        return $db;
    }

    public function testConnectPerformsVersionHandshakeAndEmitsConnected(): void
    {
        $responder = $this->responder();
        $events = new DriverOptions(
            webSocketTransportFactory: fn (): FakeDuplexTransport => new FakeDuplexTransport($responder),
        );

        $db = new Surreal($events);
        $connectedVersion = null;
        $db->subscribe('connected', function (string $version) use (&$connectedVersion): void {
            $connectedVersion = $version;
        });

        $db->connect('ws://localhost:8000/rpc', new ConnectOptions(namespace: 'test', database: 'test'));

        $this->assertTrue($db->isConnected());
        $this->assertSame('surrealdb-2.1.0', $db->version());
        $this->assertSame('surrealdb-2.1.0', $connectedVersion);
    }

    public function testQueryReturnsStatementResults(): void
    {
        $db = $this->connect();

        $this->assertSame([[['id' => 'person:tobie', 'name' => 'Tobie']]], $db->run('SELECT * FROM person'));
    }

    public function testCborCodecCanDriveWebSocketFeaturePath(): void
    {
        $responder = $this->responder();
        $captured = null;
        $db = new Surreal(new DriverOptions(
            format: CodecEnum::CBOR,
            webSocketTransportFactory: function (DriverContext $context) use ($responder, &$captured): FakeDuplexTransport {
                return $captured = new FakeDuplexTransport($responder, $context->codec);
            },
        ));

        $db->connect('ws://localhost:8000/rpc', new ConnectOptions(namespace: 'test', database: 'test'));

        $this->assertTrue($db->isConnected());
        $this->assertSame([[['id' => 'person:tobie', 'name' => 'Tobie']]], $db->run('SELECT * FROM person'));
        $this->assertNotNull($captured);
    }

    public function testSigninStoresTokens(): void
    {
        $db = $this->connect();

        $tokens = $db->signin(new RootAuth('root', 'root'));

        $this->assertSame('header.payload.signature', $tokens->access);
    }

    public function testPsr14EventsAreDispatched(): void
    {
        $responder = $this->responder();
        $dispatcher = new \SurrealDB\SDK\Events\EventDispatcher();
        $received = [];

        if ($dispatcher->provider() instanceof \SurrealDB\SDK\Events\ListenerProvider) {
            $dispatcher->provider()->on(Connected::class, function (Connected $event) use (&$received): void {
                $received[] = $event->version;
            });
        }

        $db = new Surreal(new DriverOptions(
            events: $dispatcher,
            webSocketTransportFactory: fn (): FakeDuplexTransport => new FakeDuplexTransport($responder),
        ));

        $db->connect('ws://localhost:8000/rpc', new ConnectOptions(namespace: 'test', database: 'test'));

        $this->assertSame(['surrealdb-2.1.0'], $received);
    }

    public function testLiveQueryYieldsNotifications(): void
    {
        $transport = null;
        $db = $this->connect($transport);

        $transport?->pushIncoming([
            'result' => [
                'id' => 'live-uuid',
                'action' => 'CREATE',
                'record' => 'person:1',
                'result' => ['name' => 'Tobie'],
            ],
        ]);

        $messages = [];
        foreach ($db->live('live-uuid') as $message) {
            $messages[] = $message;
            break;
        }

        $this->assertCount(1, $messages);
        $this->assertSame('CREATE', $messages[0]->action->value);
        $this->assertSame(['name' => 'Tobie'], $messages[0]->value);
    }
}
