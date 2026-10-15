<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\NamespaceAuth;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectionStatus;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Exceptions\ConnectionUnavailableException;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Live\LiveAction;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;
use SurrealDB\Tests\Fakes\SeverableWebSocketClient;
use function count;

/**
 * Reconnection, session restore, live query replay and token renewal under
 * the default synchronous scheduler, which runs no background tasks.
 */
final class WebSocketReconnectTest extends TestCase
{
    private SeverableWebSocketClient $client;

    private string $namespace;

    public function testReconnectsAndRestoresSessionsAfterTheSocketDrops(): void
    {
        $db = $this->connect();
        $events = [];

        foreach (['reconnecting', 'connected', 'disconnected'] as $event) {
            $db->subscribe($event, static function () use (&$events, $event): void {
                $events[] = $event;
            });
        }

        try {
            $db->let('greeting', 'hello');
            // Extra sessions need SurrealDB 3.
            $session = $db->isFeatureSupported(Features::sessions()) ? $db->newSession() : null;
            $session?->use($this->namespace, 'other');

            $this->client->sever();

            // The call that finds the socket dead fails; the engine has
            // reconnected by the time it returns.
            try {
                $db->run('RETURN 1');
                self::fail('Expected the call on the dropped socket to fail.');
            } catch (SurrealException) {
            }

            self::assertSame(ConnectionStatus::Connected, $db->status());
            self::assertSame(['reconnecting', 'connected'], $events);
            self::assertSame(2, $this->client->connects);

            // Namespace, database, variables, authentication and the extra
            // session all came back.
            self::assertSame(['hello'], $db->run('RETURN $greeting'));
            self::assertSame([$this->namespace], $db->run('RETURN session::ns()'));

            if ($session !== null) {
                self::assertSame(['other'], $session->run('RETURN session::db()'));
                self::assertContains($session->id(), $db->sessions());
            }
        } finally {
            $db->close();
        }
    }

    public function testStatusReconnectsADeadSocket(): void
    {
        $db = $this->connect();

        try {
            $this->client->sever();

            self::assertSame(ConnectionStatus::Connected, $db->status());
            self::assertSame([1], $db->run('RETURN 1'));
        } finally {
            $db->close();
        }
    }

    public function testDisconnectsOnceReconnectAttemptsRunOut(): void
    {
        $db = $this->connect(['attempts' => 2, 'retryDelay' => 0.001]);
        $reconnecting = 0;
        $db->subscribe('reconnecting', static function () use (&$reconnecting): void {
            $reconnecting++;
        });

        $this->client->refuseConnections();
        $this->client->sever();

        self::assertSame(ConnectionStatus::Disconnected, $db->status());
        self::assertSame(2, $reconnecting);
        self::assertSame(3, $this->client->connects);

        $this->expectException(ConnectionUnavailableException::class);
        $db->run('RETURN 1');
    }

    public function testManagedLiveQueriesAreReplayedAfterAReconnect(): void
    {
        $db = $this->connect();

        try {
            $live = $db->live(new Table('person'))->execute();
            $before = $live->id();
            $received = [];

            $db->create(new RecordId('person', 'one'))->content(['name' => 'One'])->execute();

            foreach ($live as $message) {
                $received[] = $message;

                if (count($received) === 1) {
                    // Drop the socket mid-iteration. The next write reconnects
                    // and replays the LIVE query under a new id.
                    $this->client->sever();

                    try {
                        $db->create(new RecordId('person', 'lost'))->execute();
                    } catch (SurrealException) {
                    }

                    $db->create(new RecordId('person', 'two'))->content(['name' => 'Two'])->execute();
                }

                if (count($received) === 2) {
                    break;
                }
            }

            self::assertCount(2, $received);
            self::assertSame(LiveAction::Create, $received[1]->action);
            self::assertSame('Two', $received[1]->value['name'] ?? null);
            self::assertTrue($live->isAlive());
            self::assertNotSame($before, $live->id());
            self::assertSame($live->id(), $received[1]->queryId);
            self::assertSame(
                [$live->id()],
                array_keys($db->run('INFO FOR TABLE person')[0]['lives']),
            );

            $live->kill();
            self::assertSame([], $db->run('INFO FOR TABLE person')[0]['lives']);
        } finally {
            $db->close();
        }
    }

    public function testLiveIterationEndsWhenTheConnectionCannotBeRestored(): void
    {
        $db = $this->connect(['attempts' => 1, 'retryDelay' => 0.001]);
        $live = $db->live(new Table('person'))->execute();
        $db->create(new RecordId('person', 'one'))->execute();
        $received = 0;

        foreach ($live as $_) {
            $received++;
            $this->client->refuseConnections();
            $this->client->sever();
        }

        self::assertSame(1, $received);
        self::assertFalse($live->isAlive());
        self::assertSame(ConnectionStatus::Disconnected, $db->status());
    }

    public function testTokensAreRenewedBeforeTheyExpireWithoutABackgroundTimer(): void
    {
        $setup = $this->connect();

        try {
            // Renewal is due 60 seconds before expiry: one second after signin.
            $setup->run(
                "DEFINE USER renew ON NAMESPACE PASSWORD 'secret' ROLES EDITOR DURATION FOR TOKEN 61s, FOR SESSION 1h",
            );
        } finally {
            $setup->close();
        }

        $db = $this->connect(auth: new NamespaceAuth($this->namespace, 'renew', 'secret'), define: false);

        try {
            $db->use($this->namespace, 'db');
            $first = $db->accessToken();
            $renewed = 0;
            $db->subscribe('auth', static function ($tokens) use (&$renewed): void {
                if ($tokens !== null) {
                    $renewed++;
                }
            });

            sleep(2);

            self::assertSame([1], $db->run('RETURN 1'));
            self::assertSame(1, $renewed);
            self::assertNotNull($db->accessToken());
            self::assertNotSame($first, $db->accessToken());
        } finally {
            $db->close();
        }
    }

    /**
     * @param array<string,mixed> $reconnect
     */
    private function connect(
        array $reconnect = ['retryDelay' => 0.001],
        RootAuth|NamespaceAuth|null $auth = null,
        bool $define = true,
    ): Surreal {
        $this->client = new SeverableWebSocketClient();
        $client = $this->client;
        $this->namespace ??= 'reconnect_' . bin2hex(random_bytes(4));

        $db = new Surreal(new DriverOptions(
            webSocketClientFactory: static fn (): SeverableWebSocketClient => $client,
        ));

        try {
            $db->connect(getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc', new ConnectOptions(
                authentication: $auth ?? new RootAuth('root', 'root'),
                reconnect: $reconnect,
            ));

            if ($define) {
                $db->run("DEFINE NAMESPACE IF NOT EXISTS {$this->namespace}; USE NS {$this->namespace}; DEFINE DATABASE IF NOT EXISTS db; DEFINE DATABASE IF NOT EXISTS other;");
                $db->use($this->namespace, 'db');
                $db->run('DEFINE TABLE IF NOT EXISTS person SCHEMALESS PERMISSIONS FULL');
            }
        } catch (\Throwable $error) {
            if (getenv('SURREALDB_REQUIRE_LOCAL') === '1') {
                self::fail('Local SurrealDB test database is required but unavailable: ' . $error->getMessage());
            }

            self::markTestSkipped('Local SurrealDB test database is not available: ' . $error->getMessage());
        }

        return $db;
    }
}
