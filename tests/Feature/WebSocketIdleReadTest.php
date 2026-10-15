<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SurrealDB\Auth\RootAuth;
use SurrealDB\Connection\ConnectOptions;
use SurrealDB\Connection\DriverOptions;
use SurrealDB\Surreal;
use SurrealDB\Transport\StreamWebSocketClient;

/**
 * A WebSocket read that waits longer than the socket's read timeout (60 s by
 * default) is an idle connection, not a closed one. These tests shrink the
 * poll interval so the wait is a second instead of a minute.
 */
final class WebSocketIdleReadTest extends TestCase
{
    public function testResponseSlowerThanThePollIntervalIsStillReceived(): void
    {
        $db = new Surreal(new DriverOptions(
            webSocketClientFactory: static fn (): StreamWebSocketClient => new StreamWebSocketClient(pollInterval: 0.2),
        ));

        $this->connect($db);

        try {
            self::assertSame(1, $db->run('SLEEP 1s; RETURN 1')[1]);
            self::assertTrue($db->isConnected());
            self::assertSame([2], $db->run('RETURN 2'));
        } finally {
            $db->close();
        }
    }

    public function testReceiveTimeoutReturnsNullWithoutClosingTheSocket(): void
    {
        $client = new StreamWebSocketClient();

        try {
            $client->connect($this->url(), ['json']);
        } catch (\Throwable $error) {
            $this->handleUnavailableDatabase($error);
        }

        try {
            self::assertNull($client->receive(0.2));
            self::assertTrue($client->isConnected());

            $client->send('{"id":"1","method":"version"}');
            $reply = $client->receive(5.0);

            self::assertIsString($reply);
            self::assertStringContainsString('surrealdb-', $reply);
        } finally {
            $client->close();
        }
    }

    private function connect(Surreal $db): void
    {
        try {
            $db->connect($this->url(), new ConnectOptions(authentication: new RootAuth('root', 'root')));
        } catch (\Throwable $error) {
            $this->handleUnavailableDatabase($error);
        }
    }

    private function url(): string
    {
        return getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc';
    }

    private function handleUnavailableDatabase(\Throwable $error): never
    {
        if (getenv('SURREALDB_REQUIRE_LOCAL') === '1') {
            self::fail('Local SurrealDB test database is required but unavailable: ' . $error->getMessage());
        }

        self::markTestSkipped('Local SurrealDB test database is not available: ' . $error->getMessage());
    }
}
