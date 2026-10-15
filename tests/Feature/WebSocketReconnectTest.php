<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\NamespaceAuth;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectionController;
use SurrealDB\SDK\Connection\ConnectionStatus;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Exceptions\ConnectionUnavailableException;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Live\LiveAction;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Support\Jwt;
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

    public function testAThrowingTransportFactoryCountsAsAFailedAttempt(): void
    {
        $calls = 0;
        $db = $this->connect(clientFactory: function () use (&$calls): SeverableWebSocketClient {
            // The first reconnect attempt cannot even build a client.
            if (++$calls === 2) {
                throw new \RuntimeException('No client available.');
            }

            return $this->client;
        });
        $errors = [];
        $db->subscribe('error', static function (\Throwable $error) use (&$errors): void {
            $errors[] = $error->getMessage();
        });

        try {
            $this->client->sever();

            try {
                $db->run('RETURN 1');
                self::fail('Expected the call on the dropped socket to fail.');
            } catch (SurrealException) {
            }

            self::assertSame(ConnectionStatus::Connected, $db->status());
            self::assertSame([1], $db->run('RETURN 1'));
            self::assertSame(3, $calls);
            self::assertContains('No client available.', $errors);
        } finally {
            $db->close();
        }
    }

    public function testClosingFromAReconnectingListenerStopsTheReconnect(): void
    {
        $db = $this->connect();
        $db->subscribe('reconnecting', static function () use ($db): void {
            $db->close();
        });

        $this->client->sever();

        try {
            $db->run('RETURN 1');
            self::fail('Expected the call on the dropped socket to fail.');
        } catch (SurrealException) {
        }

        self::assertSame(ConnectionStatus::Disconnected, $db->status());
        self::assertSame(1, $this->client->connects);
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

    public function testNotificationsReceivedBeforeAReconnectAreStillYielded(): void
    {
        $db = $this->connect();

        try {
            $live = $db->live(new Table('person'))->execute();

            // Delivered to the feed (while waiting for the next call's
            // reply), but not iterated before the socket drops.
            $db->create(new RecordId('person', 'before'))->execute();
            $db->run('RETURN 1');
            $this->client->sever();
            self::assertSame(ConnectionStatus::Connected, $db->status());
            $db->create(new RecordId('person', 'after'))->execute();

            $records = [];

            foreach ($live as $message) {
                $records[] = (string) $message->record;

                if (count($records) === 2) {
                    break;
                }
            }

            self::assertSame(['person:before', 'person:after'], $records);
        } finally {
            $db->close();
        }
    }

    public function testLiveQueriesAreReplayedBeforeConnectedIsAnnounced(): void
    {
        $db = $this->connect();

        try {
            $live = $db->live(new Table('person'))->execute();
            $db->subscribe('connected', static function () use ($db): void {
                $db->create(new RecordId('person', 'on_connected'))->execute();
            });

            $this->client->sever();
            self::assertSame(ConnectionStatus::Connected, $db->status());
            $db->create(new RecordId('person', 'later'))->execute();

            $records = [];

            foreach ($live as $message) {
                $records[] = (string) $message->record;

                if (count($records) === 2) {
                    break;
                }
            }

            self::assertSame(['person:on_connected', 'person:later'], $records);
        } finally {
            $db->close();
        }
    }

    public function testLosingTheSocketDuringReplayKeepsTheSubscriptionForTheNextOne(): void
    {
        $db = $this->connect();

        try {
            $live = $db->live(new Table('person'))->execute();
            $events = [];

            foreach (['reconnecting', 'connected'] as $event) {
                $db->subscribe($event, static function () use (&$events, $event): void {
                    $events[] = $event;
                });
            }

            // Drop the first replacement socket just as the LIVE query is
            // re-issued on it.
            $client = $this->client;
            $client->beforeSend = static function (string $payload) use ($client): ?string {
                if (str_contains($payload, 'LIVE SELECT')) {
                    $client->beforeSend = null;
                    $client->sever();
                }

                return null;
            };

            $client->sever();
            self::assertSame(ConnectionStatus::Connected, $db->status());
            self::assertSame(['reconnecting', 'reconnecting', 'connected'], $events);
            self::assertTrue($live->isAlive());

            $db->create(new RecordId('person', 'after'))->execute();

            foreach ($live as $message) {
                self::assertSame('person:after', (string) $message->record);

                break;
            }
        } finally {
            $db->close();
        }
    }

    public function testLosingTheSocketWhileRestoringTheSessionReconnectsAgain(): void
    {
        $db = $this->connect();

        try {
            $db->let('greeting', 'hello');
            $events = [];

            foreach (['reconnecting', 'connected', 'disconnected'] as $event) {
                $db->subscribe($event, static function () use (&$events, $event): void {
                    $events[] = $event;
                });
            }

            // Drop the first replacement socket as it restores the variable.
            $client = $this->client;
            $client->beforeSend = static function (string $payload) use ($client): ?string {
                if (str_contains($payload, 'greeting')) {
                    $client->beforeSend = null;
                    $client->sever();
                }

                return null;
            };

            $client->sever();

            self::assertSame(ConnectionStatus::Connected, $db->status());
            self::assertSame(['reconnecting', 'reconnecting', 'connected'], $events);
            self::assertSame(['hello'], $db->run('RETURN $greeting'));
        } finally {
            $db->close();
        }
    }

    public function testClosingFromAReplayErrorListenerLeavesTheConnectionClosed(): void
    {
        $db = $this->connect();

        try {
            $live = $db->live(new Table('person'))->execute();
            $events = [];

            foreach (['connected', 'disconnected'] as $event) {
                $db->subscribe($event, static function () use (&$events, $event): void {
                    $events[] = $event;
                });
            }

            $db->subscribe('error', static function () use ($db): void {
                $db->close();
            });

            // Make the server reject the replayed query (same length, so a
            // CBOR frame stays well formed).
            $this->client->beforeSend = static function (string $payload): ?string {
                return str_contains($payload, 'LIVE SELECT')
                    ? str_replace('LIVE SELECT', 'LIVE SELEKT', $payload)
                    : null;
            };

            $this->client->sever();

            self::assertSame(ConnectionStatus::Disconnected, $db->status());
            self::assertSame(['disconnected'], $events);
            self::assertFalse($live->isAlive());
        } finally {
            $db->close();
        }
    }

    public function testAnUnmanagedSubscriptionIsNotAliveAfterAReconnect(): void
    {
        $db = $this->connect();

        try {
            [$id] = $db->run('LIVE SELECT * FROM person');
            $live = $db->liveOf($id);
            self::assertTrue($live->isAlive());

            $this->client->sever();
            self::assertSame(ConnectionStatus::Connected, $db->status());

            // The new socket does not carry the query, and the SDK did not
            // start it, so it cannot re-issue it.
            self::assertFalse($live->isAlive());
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

    public function testAFailedRenewalIsRetried(): void
    {
        $this->defineShortLivedUser();

        $calls = 0;
        $namespace = $this->namespace;
        $provider = static function (?string $session) use (&$calls, $namespace): NamespaceAuth {
            // The second call is the first renewal: fail it once.
            if (++$calls === 2) {
                throw new \RuntimeException('Credentials store briefly unavailable.');
            }

            return new NamespaceAuth($namespace, 'renew', 'secret');
        };
        $db = $this->connect(auth: $provider, define: false);

        try {
            $db->use($this->namespace, 'db');
            $first = $db->accessToken();
            $errors = 0;
            $db->subscribe('error', static function () use (&$errors): void {
                $errors++;
            });

            sleep(2);
            self::assertSame([1], $db->run('RETURN 1'));
            self::assertSame(1, $errors);
            self::assertSame($first, $db->accessToken());

            sleep(6);
            self::assertSame([1], $db->run('RETURN 1'));
            self::assertSame(3, $calls);
            self::assertNotSame($first, $db->accessToken());
        } finally {
            $db->close();
        }
    }

    public function testRenewalThatGetsTheSameTokenChecksAgainAtExpiry(): void
    {
        $this->defineShortLivedUser();

        $setup = $this->connect(auth: new NamespaceAuth($this->namespace, 'renew', 'secret'), define: false);
        $token = $setup->accessToken();
        $setup->close();
        self::assertNotNull($token);

        // A provider that hands out a fixed token, as a Token or string does.
        $calls = 0;
        $db = $this->connect(auth: static function () use (&$calls, $token): string {
            $calls++;

            return $token;
        }, define: false);

        try {
            $db->use($this->namespace, 'db');

            sleep(2);
            self::assertSame([1], $db->run('RETURN 1'));
            self::assertSame(2, $calls);

            // Not dropped: due again when the token expires.
            $deadlines = (new \ReflectionProperty(ConnectionController::class, 'renewAt'))->getValue($db->connection());
            self::assertSame(Jwt::expiry($token), $deadlines[''] ?? null);
        } finally {
            $db->close();
        }
    }

    public function testCommittingATransactionRenewsADueToken(): void
    {
        $this->defineShortLivedUser();
        $db = $this->connect(auth: new NamespaceAuth($this->namespace, 'renew', 'secret'), define: false);

        try {
            if (!$db->isFeatureSupported(Features::transactions())) {
                self::markTestSkipped('Interactive transactions need SurrealDB 3.0 or later.');
            }

            $db->use($this->namespace, 'db');
            $renewed = 0;
            $db->subscribe('auth', static function ($tokens) use (&$renewed): void {
                if ($tokens !== null) {
                    $renewed++;
                }
            });

            $txn = $db->beginTransaction();
            $txn->run('CREATE person:committed');
            sleep(2);
            $txn->commit();

            self::assertSame(1, $renewed);
            self::assertCount(1, $db->select(new RecordId('person', 'committed'))->execute());
        } finally {
            $db->close();
        }
    }

    public function testSelectingANamespaceRenewsADueToken(): void
    {
        $this->defineShortLivedUser();
        $db = $this->connect(auth: new NamespaceAuth($this->namespace, 'renew', 'secret'), define: false);

        try {
            $first = $db->accessToken();
            $renewed = 0;
            $db->subscribe('auth', static function ($tokens) use (&$renewed): void {
                if ($tokens !== null) {
                    $renewed++;
                }
            });

            sleep(2);
            $db->use($this->namespace, 'db');

            self::assertSame(1, $renewed);
            self::assertNotSame($first, $db->accessToken());
        } finally {
            $db->close();
        }
    }

    /**
     * A namespace user whose tokens are due for renewal one second after signin.
     */
    private function defineShortLivedUser(): void
    {
        $setup = $this->connect();

        try {
            $setup->run(
                "DEFINE USER renew ON NAMESPACE PASSWORD 'secret' ROLES EDITOR DURATION FOR TOKEN 61s, FOR SESSION 1h",
            );
        } finally {
            $setup->close();
        }
    }

    /**
     * @param array<string,mixed> $reconnect
     * @param (\Closure(): SeverableWebSocketClient)|null $clientFactory
     */
    private function connect(
        array $reconnect = ['retryDelay' => 0.001],
        RootAuth|NamespaceAuth|\Closure|null $auth = null,
        bool $define = true,
        ?\Closure $clientFactory = null,
    ): Surreal {
        $this->client = new SeverableWebSocketClient();
        $client = $this->client;
        $this->namespace ??= 'reconnect_' . bin2hex(random_bytes(4));

        $db = new Surreal(new DriverOptions(
            webSocketClientFactory: $clientFactory ?? static fn (): SeverableWebSocketClient => $client,
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
