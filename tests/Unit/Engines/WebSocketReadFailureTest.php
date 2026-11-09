<?php

namespace SurrealDB\Tests\Unit\Engines;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Connection\ConnectionStatus;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Surreal;
use SurrealDB\Tests\Fakes\DroppingTransport;
use SurrealDB\Tests\Fakes\FakeDuplexTransport;

/**
 * A read that throws (a socket closed in the middle of a message, a message
 * over the client's size limit) loses the connection, as the end of the
 * stream does.
 */
final class WebSocketReadFailureTest extends TestCase
{
    /** @var list<DroppingTransport> */
    private array $sockets = [];

    public function testAReadThatThrowsLosesTheConnection(): void
    {
        $db = $this->connect(reconnect: false);
        $events = [];

        foreach (['disconnected', 'error'] as $event) {
            $db->subscribe($event, static function () use (&$events, $event): void {
                $events[] = $event;
            });
        }

        $this->sockets[0]->throwOnNextReply = new \RuntimeException('The socket closed in the middle of a message.');

        try {
            $db->run('RETURN 1');
            self::fail('The call should fail with its socket.');
        } catch (SurrealException) {
        }

        // It used to escape raw, and leave the connection reporting
        // Connected, with no disconnected event.
        self::assertContains('disconnected', $events);
        self::assertContains('error', $events);
        self::assertFalse($db->isConnected());
    }

    public function testAReadThatThrowsReconnects(): void
    {
        $db = $this->connect(reconnect: true);
        $this->sockets[0]->throwOnNextReply = new \RuntimeException('Message over the size limit.');

        try {
            $db->run('RETURN 1');
            self::fail('The call should fail with its socket.');
        } catch (SurrealException) {
        }

        self::assertSame(ConnectionStatus::Connected, $db->status());
        self::assertCount(2, $this->sockets);
        self::assertSame([[1]], $db->run('RETURN 1'));
    }

    private function connect(bool $reconnect): Surreal
    {
        $responder = static function (string $method, array $params, ?string $id): array {
            return match ($method) {
                'version' => ['id' => $id, 'result' => 'surrealdb-3.0.0'],
                'query' => ['id' => $id, 'result' => [['status' => 'OK', 'result' => [1]]]],
                default => ['id' => $id, 'result' => null],
            };
        };

        $db = new Surreal(new DriverOptions(
            webSocketTransportFactory: function () use ($responder): DroppingTransport {
                return $this->sockets[] = new DroppingTransport(new FakeDuplexTransport($responder));
            },
        ));
        $db->connect('ws://localhost:8000/rpc', new ConnectOptions(
            namespace: 'test',
            database: 'test',
            reconnect: $reconnect ? ['retryDelay' => 0.001] : false,
        ));

        return $db;
    }
}
