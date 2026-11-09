<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectionStatus;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Exceptions\ConnectionUnavailableException;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Live\LiveAction;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;
use SurrealDB\Tests\Fakes\SeverableWebSocketClient;

/**
 * Under the default synchronous scheduler nothing reads the socket between
 * calls, so a connection that dies must still stop reporting itself as
 * connected.
 */
final class WebSocketConnectionLossTest extends TestCase
{
    private SeverableWebSocketClient $client;

    public function testStatusReportsDisconnectedOnceTheSocketIsGone(): void
    {
        $db = $this->connect();
        $disconnected = 0;
        $db->subscribe('disconnected', static function () use (&$disconnected): void {
            $disconnected++;
        });

        self::assertTrue($db->isConnected());

        $this->client->sever();

        self::assertSame(ConnectionStatus::Disconnected, $db->status());
        self::assertFalse($db->isConnected());
        self::assertSame(1, $disconnected);

        $this->expectException(ConnectionUnavailableException::class);
        $db->run('RETURN 1');
    }

    public function testAStatusCheckKeepsNotificationsThatArrivedBeforeTheClose(): void
    {
        $db = $this->connect();

        try {
            $namespace = 'loss_' . bin2hex(random_bytes(4));
            $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db;");
            $db->use($namespace, 'db');
            $db->run('DEFINE TABLE person SCHEMALESS PERMISSIONS FULL');
            $live = $db->live(new Table('person'))->execute();

            $other = new Surreal();
            $other->connect(getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc', new ConnectOptions(
                authentication: new RootAuth('root', 'root'),
                namespace: $namespace,
                database: 'db',
            ));
            $other->create(new RecordId('person', 'one'))->execute();
            $other->close();

            // The notification is on its way, then the server closes.
            usleep(300_000);
            $this->client->closeFromServer();

            self::assertSame(ConnectionStatus::Disconnected, $db->status());

            $actions = [];

            try {
                foreach ($live as $message) {
                    $actions[] = $message->action;
                }

                self::fail('Reading past a lost connection should say so.');
            } catch (ConnectionUnavailableException) {
                // Once what arrived before the close has been read.
            }

            self::assertSame([LiveAction::Create], $actions);
        } finally {
            $db->close();
        }
    }

    public function testCallOnADeadSocketFailsAndDisconnects(): void
    {
        $db = $this->connect();
        self::assertSame([1], $db->run('RETURN 1'));

        $this->client->sever();

        try {
            $db->run('RETURN 2');
            self::fail('Expected the call on a dead socket to fail.');
        } catch (SurrealException) {
            // Expected: the write fails and the connection is reported lost.
        }

        self::assertSame(ConnectionStatus::Disconnected, $db->status());
    }

    private function connect(): Surreal
    {
        $this->client = new SeverableWebSocketClient();
        $client = $this->client;

        $db = new Surreal(new DriverOptions(
            webSocketClientFactory: static fn (): SeverableWebSocketClient => $client,
        ));

        try {
            $db->connect(
                getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc',
                new ConnectOptions(authentication: new RootAuth('root', 'root'), reconnect: false),
            );
        } catch (\Throwable $error) {
            if (getenv('SURREALDB_REQUIRE_LOCAL') === '1') {
                self::fail('Local SurrealDB test database is required but unavailable: ' . $error->getMessage());
            }

            self::markTestSkipped('Local SurrealDB test database is not available: ' . $error->getMessage());
        }

        return $db;
    }
}
