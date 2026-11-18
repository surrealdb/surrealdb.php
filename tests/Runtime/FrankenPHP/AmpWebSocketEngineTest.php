<?php

namespace SurrealDB\Tests\Runtime\FrankenPHP;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use SurrealDB\Auth\NamespaceAuth;
use SurrealDB\Auth\RecordAccessAuth;
use SurrealDB\Auth\RootAuth;
use SurrealDB\Connection\ConnectionState;
use SurrealDB\Connection\ConnectionStatus;
use SurrealDB\Connection\ConnectOptions;
use SurrealDB\Connection\DriverContext;
use SurrealDB\Contracts\MiddlewareInterface;
use SurrealDB\Exceptions\ConnectionUnavailableException;
use SurrealDB\Exceptions\SurrealException;
use SurrealDB\Protocol\Features;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;
use SurrealDB\Runtime\Runtime;
use SurrealDB\Surreal;
use SurrealDB\Transport\Amp\AmpWebSocketTransport;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\Table;
use SurrealDB\Tests\Fakes\BeforeRequest;

/**
 * Drives the WebSocket engine on the Revolt event loop against a real
 * SurrealDB server, so the background read loop (not the synchronous drive
 * callback) delivers every reply.
 *
 * Self-skips when the Amp stack is not installed, and when no server is
 * reachable unless SURREALDB_REQUIRE_LOCAL=1.
 */
final class AmpWebSocketEngineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(EventLoop::class) || !function_exists('Amp\Websocket\Client\connect')) {
            $this->markTestSkipped('revolt/event-loop and amphp/websocket-client are required.');
        }
    }

    public function testConnectCompletesAndQueriesRun(): void
    {
        $result = $this->inLoop(function (): array {
            $db = new Surreal(Runtime::amp());
            $this->connect($db);

            try {
                return [$db->version(), $db->run('RETURN 1')];
            } finally {
                $db->close();
            }
        });

        $this->assertStringStartsWith('surrealdb-', $result[0]);
        $this->assertSame([1], $result[1]);
    }

    /**
     * Its own process: the event loop is global, and must hold nothing from
     * other tests.
     */
    #[RunInSeparateProcess]
    public function testClosingLetsTheEventLoopFinish(): void
    {
        $outcome = null;

        // Unreferenced, so it does not keep the loop running itself, but
        // stops a loop that something else keeps running.
        $watchdog = EventLoop::unreference(EventLoop::delay(5, static function (): void {
            EventLoop::getDriver()->stop();
        }));

        EventLoop::queue(function () use (&$outcome): void {
            try {
                // Signed in, so a token renewal is scheduled, and pinging.
                $db = new Surreal(Runtime::amp());
                $this->connect($db);
                $db->run('RETURN 1');
                $db->close();
                $outcome = true;
            } catch (\Throwable $error) {
                $outcome = $error;
            }
        });

        $start = microtime(true);
        EventLoop::run();
        $elapsed = microtime(true) - $start;
        EventLoop::cancel($watchdog);

        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        $this->assertTrue($outcome);
        $this->assertLessThan(4, $elapsed, 'Something kept the event loop running after close().');
    }

    public function testReconnectRestoresTheSessionWithoutDeadlocking(): void
    {
        $result = $this->inLoop(function (): array {
            /** @var list<AmpWebSocketTransport> $transports */
            $transports = [];
            $options = Runtime::amp();
            $options->webSocketTransportFactory = static function (DriverContext $context, ConnectionState $state) use (&$transports): AmpWebSocketTransport {
                return $transports[] = new AmpWebSocketTransport($context, $state->endpoint);
            };

            $db = new Surreal($options);
            $this->connect($db);
            $connected = 0;
            $db->subscribe('connected', static function () use (&$connected): void {
                $connected++;
            });

            try {
                $db->let('greeting', 'hello');
                $transports[0]->close();

                $deadline = microtime(true) + 5;

                while ($connected === 0 && microtime(true) < $deadline) {
                    $this->delay(0.05);
                }

                return [count($transports), $db->status(), $db->run('RETURN $greeting')];
            } finally {
                $db->close();
            }
        });

        $this->assertSame(2, $result[0]);
        $this->assertSame(ConnectionStatus::Connected, $result[1]);
        $this->assertSame(['hello'], $result[2]);
    }

    public function testACallFromAnotherTaskDoesNotRunOnASessionStillBeingRestored(): void
    {
        $result = $this->inLoop(function (): array {
            /** @var list<AmpWebSocketTransport> $transports */
            $transports = [];
            /** @var (\Closure(): void)|null $other */
            $other = null;

            // The restore on the new socket is held just before it sets the
            // session's variable again, while another task makes a call.
            $options = Runtime::amp();
            $options->middleware = [new BeforeRequest(function (RpcRequest $request) use (&$transports, &$other): void {
                if ($request->method === 'let' && count($transports) === 2 && $other !== null) {
                    EventLoop::queue($other);
                    $other = null;
                    $this->delay(0.05);
                }
            })];
            $options->webSocketTransportFactory = static function (DriverContext $context, ConnectionState $state) use (&$transports): AmpWebSocketTransport {
                return $transports[] = new AmpWebSocketTransport($context, $state->endpoint);
            };

            $db = new Surreal($options);
            $this->connect($db);
            $namespace = 'amp_restore_' . bin2hex(random_bytes(4));
            $connected = 0;
            $db->subscribe('connected', static function () use (&$connected): void {
                $connected++;
            });

            try {
                $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db;");
                $db->use($namespace, 'db');
                $db->let('greeting', 'hello');
                $seen = [];
                $other = static function () use ($db, &$seen): void {
                    try {
                        $seen[] = $db->run('RETURN $greeting')[0];
                    } catch (\Throwable $error) {
                        $seen[] = $error::class;
                    }
                };

                $transports[0]->close();
                $this->waitFor(static function () use (&$connected): bool {
                    return $connected === 1;
                });

                return [$seen, $db->run('RETURN $greeting')];
            } finally {
                $db->close();
            }
        });

        // Refused, rather than answered with NONE: what the variable is on
        // the new socket until the restore sets it.
        $this->assertSame([[ConnectionUnavailableException::class], ['hello']], $result);
    }

    public function testACallFromAnotherTaskDoesNotRunWhereALiveQueryIsBeingReplayed(): void
    {
        $result = $this->inLoop(function (): array {
            /** @var list<AmpWebSocketTransport> $transports */
            $transports = [];
            /** @var (\Closure(): void)|null $other */
            $other = null;

            // The replay of the live query, for which the session is back in
            // the database the query started in, is held while another task
            // writes.
            $options = Runtime::amp();
            $options->middleware = [new BeforeRequest(function (RpcRequest $request) use (&$transports, &$other): void {
                $replay = $request->method === 'query' && str_starts_with((string) ($request->params[0] ?? ''), 'LIVE SELECT');

                if ($replay && count($transports) === 2 && $other !== null) {
                    EventLoop::queue($other);
                    $other = null;
                    $this->delay(0.05);
                }
            })];
            $options->webSocketTransportFactory = static function (DriverContext $context, ConnectionState $state) use (&$transports): AmpWebSocketTransport {
                return $transports[] = new AmpWebSocketTransport($context, $state->endpoint);
            };

            $db = new Surreal($options);
            $this->connect($db);
            $namespace = 'amp_replay_' . bin2hex(random_bytes(4));
            $connected = 0;
            $db->subscribe('connected', static function () use (&$connected): void {
                $connected++;
            });

            try {
                $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db_started; DEFINE DATABASE db_current;");
                $db->use($namespace, 'db_started');
                $db->run('DEFINE TABLE feed SCHEMALESS; DEFINE TABLE note SCHEMALESS;');
                $live = $db->live(new Table('feed'))->execute();

                // The session has since moved to another database.
                $db->use($namespace, 'db_current');
                $db->run('DEFINE TABLE note SCHEMALESS');
                $outcome = null;
                $other = static function () use ($db, &$outcome): void {
                    try {
                        $db->create(new Table('note'))->content(['text' => 'hello'])->execute();
                        $outcome = 'written';
                    } catch (\Throwable $error) {
                        $outcome = $error::class;
                    }
                };

                $transports[0]->close();
                $this->waitFor(static function () use (&$connected): bool {
                    return $connected === 1;
                });

                $current = $db->run('SELECT VALUE text FROM note')[0];
                $db->use($namespace, 'db_started');

                return [$outcome, $current, $db->run('SELECT VALUE text FROM note')[0], $live->isAlive()];
            } finally {
                $db->close();
            }
        });

        // Refused, rather than written to the database the live query
        // started in.
        $this->assertSame([ConnectionUnavailableException::class, [], [], true], $result);
    }

    public function testASocketLostBetweenTwoRestoreRequestsIsNotTakenForConnected(): void
    {
        $result = $this->inLoop(function (): array {
            /** @var list<AmpWebSocketTransport> $transports */
            $transports = [];
            $options = Runtime::amp();
            $options->webSocketTransportFactory = static function (DriverContext $context, ConnectionState $state) use (&$transports): AmpWebSocketTransport {
                return $transports[] = new AmpWebSocketTransport($context, $state->endpoint);
            };

            $db = new Surreal($options);
            $this->connect($db);
            $namespace = 'amp_between_' . bin2hex(random_bytes(4));
            $connected = [];
            $db->subscribe('connected', static function () use (&$connected, &$transports): void {
                $connected[] = count($transports);
            });

            try {
                $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db;");
                $db->use($namespace, 'db');
                $lost = false;

                // The first replacement socket is lost once its restore has
                // selected the database again: no request is waiting on it,
                // so nothing fails, and the restore runs on to its end.
                $db->subscribe('using', function () use (&$transports, &$lost): void {
                    if (count($transports) === 2 && !$lost) {
                        $transports[1]->close();
                        $this->delay(0.03);
                        $lost = true;
                    }
                });

                $transports[0]->close();
                $this->waitFor(static function () use (&$lost): bool {
                    return $lost;
                });

                // That restore is over by now, and the next socket (0.2 s
                // after the loss) is not open yet.
                $this->delay(0.02);
                $meanwhile = $db->status();

                $this->waitFor(static function () use (&$transports, $db): bool {
                    return count($transports) === 3 && $db->status() === ConnectionStatus::Connected;
                });

                return [$meanwhile, $connected, $db->run('RETURN session::db()')];
            } finally {
                $db->close();
            }
        });

        // Connected, and announced, only by the socket that took its place.
        $this->assertSame([ConnectionStatus::Reconnecting, [3], ['db']], $result);
    }

    public function testAnAuthProviderMayQueryWhileItsSessionIsRestored(): void
    {
        $result = $this->inLoop(function (): array {
            $user = 'asking_' . bin2hex(random_bytes(4));
            $setup = new Surreal(Runtime::amp());
            $this->connect($setup);

            try {
                // A token too close to its expiry for a reconnect to reuse,
                // so the new socket signs in with the provider again.
                $setup->run("DEFINE USER {$user} ON ROOT PASSWORD 'secret' ROLES OWNER DURATION FOR TOKEN 30s, FOR SESSION 1h");
            } finally {
                $setup->close();
            }

            /** @var list<AmpWebSocketTransport> $transports */
            $transports = [];
            $options = Runtime::amp();
            $options->webSocketTransportFactory = static function (DriverContext $context, ConnectionState $state) use (&$transports): AmpWebSocketTransport {
                return $transports[] = new AmpWebSocketTransport($context, $state->endpoint);
            };

            $db = new Surreal($options);
            $asked = [];

            // A provider that looks something up over the connection it is
            // signing in, noting which socket that was.
            $provider = static function () use ($db, &$transports, &$asked, $user): RootAuth {
                try {
                    $db->run('RETURN 1');
                    $asked[] = [count($transports), 'answered'];
                } catch (\Throwable $error) {
                    $asked[] = [count($transports), $error::class];
                }

                return new RootAuth($user, 'secret');
            };

            $this->connect($db, $provider);
            $connected = 0;
            $db->subscribe('connected', static function () use (&$connected): void {
                $connected++;
            });

            try {
                $transports[0]->close();
                $this->waitFor(static function () use (&$connected): bool {
                    return $connected === 1;
                });

                return [$asked, $db->run('RETURN 2')];
            } finally {
                $db->close();
            }
        });

        [$asked, $after] = $result;
        $this->assertSame([1, 'answered'], $asked[0], 'while connecting');
        $this->assertContains([2, 'answered'], $asked, 'while restoring the session on the new socket');
        $this->assertSame([2], $after);
    }

    public function testATokenIsNotRenewedOnASocketStillBeingRestored(): void
    {
        $during = $this->inLoop(function (): array {
            $namespace = 'amp_renew_' . bin2hex(random_bytes(4));
            $setup = new Surreal(Runtime::amp());
            $this->connect($setup);

            try {
                // Renewal is due 60 seconds before expiry: about a second in.
                $setup->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace};"
                    . " DEFINE USER renew ON NAMESPACE PASSWORD 'secret' ROLES EDITOR DURATION FOR TOKEN 61s, FOR SESSION 1h");
            } finally {
                $setup->close();
            }

            /** @var list<AmpWebSocketTransport> $transports */
            $transports = [];
            $hold = true;
            $held = false;
            $during = [];

            // The restore on the new socket has signed the session in, and is
            // held until after that token falls due for renewal.
            $options = Runtime::amp();
            $options->middleware = [new BeforeRequest(function (RpcRequest $request) use (&$transports, &$hold, &$held, &$during): void {
                if ($held) {
                    $during[] = $request->method;
                } elseif ($hold && $request->method === 'let' && count($transports) === 2) {
                    $hold = false;
                    $held = true;
                    $this->delay(1.6);
                    $held = false;
                }
            })];
            $options->webSocketTransportFactory = static function (DriverContext $context, ConnectionState $state) use (&$transports): AmpWebSocketTransport {
                return $transports[] = new AmpWebSocketTransport($context, $state->endpoint);
            };

            $db = new Surreal($options);
            $this->connect($db, static fn (): NamespaceAuth => new NamespaceAuth($namespace, 'renew', 'secret'));
            $connected = 0;
            $db->subscribe('connected', static function () use (&$connected): void {
                $connected++;
            });

            try {
                $db->let('greeting', 'hello');
                $transports[0]->close();
                $this->waitFor(static function () use (&$connected): bool {
                    return $connected === 1;
                });

                return $during;
            } finally {
                $db->close();
            }
        });

        // No sign-in of the renewal timer's went out meanwhile.
        $this->assertSame([], $during);
    }

    public function testManagedLiveIterationSurvivesAReconnect(): void
    {
        $result = $this->inLoop(function (): array {
            /** @var list<AmpWebSocketTransport> $transports */
            $transports = [];
            $options = Runtime::amp();
            $options->webSocketTransportFactory = static function (DriverContext $context, ConnectionState $state) use (&$transports): AmpWebSocketTransport {
                return $transports[] = new AmpWebSocketTransport($context, $state->endpoint);
            };

            $db = new Surreal($options);
            $this->connect($db);
            $namespace = 'amp_live_' . bin2hex(random_bytes(4));
            $connected = 0;
            $db->subscribe('connected', static function () use (&$connected): void {
                $connected++;
            });

            try {
                $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db;");
                $db->use($namespace, 'db');
                $db->run('DEFINE TABLE person SCHEMALESS PERMISSIONS FULL');

                $live = $db->live(new Table('person'))->execute();
                $before = $live->id();
                $messages = [];
                $done = false;

                // A separate fiber iterates throughout, across the reconnect.
                EventLoop::queue(static function () use ($live, &$messages, &$done): void {
                    foreach ($live as $message) {
                        $messages[] = $message;

                        if (count($messages) === 2) {
                            break;
                        }
                    }

                    $done = true;
                });

                $db->create(new RecordId('person', 'one'))->execute();
                $this->waitFor(static function () use (&$messages): bool { return count($messages) === 1; });

                $transports[0]->close();
                $this->waitFor(static function () use (&$connected): bool { return $connected === 1; });

                $db->create(new RecordId('person', 'two'))->execute();
                $this->waitFor(static function () use (&$done): bool { return $done; });

                return [$before, $live->id(), array_map(static fn ($m): string => $m->queryId, $messages), $done];
            } finally {
                $db->close();
            }
        });

        [$before, $after, $queryIds, $done] = $result;
        $this->assertTrue($done);
        $this->assertNotSame($before, $after);
        $this->assertSame([$before, $after], $queryIds);
    }

    public function testKillingAManagedSubscriptionWaitingForItsReplayStopsIt(): void
    {
        $result = $this->inLoop(function (): array {
            /** @var list<AmpWebSocketTransport> $transports */
            $transports = [];
            $options = Runtime::amp();
            $options->webSocketTransportFactory = static function (DriverContext $context, ConnectionState $state) use (&$transports): AmpWebSocketTransport {
                return $transports[] = new AmpWebSocketTransport($context, $state->endpoint);
            };

            $db = new Surreal($options);
            $this->connect($db);
            $namespace = 'amp_kill_' . bin2hex(random_bytes(4));

            try {
                $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db;");
                $db->use($namespace, 'db');
                $db->run('DEFINE TABLE person SCHEMALESS PERMISSIONS FULL');
                $live = $db->live(new Table('person'))->execute();
                $before = $live->id();

                // Killed after its socket is lost, before the reconnect
                // (`retryDelay` 0.05 s) replays it: there is no KILL to send.
                $transports[0]->close();
                $this->waitFor(static function () use ($db): bool {
                    return $db->status() === ConnectionStatus::Reconnecting;
                });
                $live->kill();

                $this->waitFor(static function () use ($db): bool {
                    return $db->status() === ConnectionStatus::Connected;
                });

                // Not replayed either.
                return [$before, $live->id(), $live->isAlive(), $db->run('RETURN 1')];
            } finally {
                $db->close();
            }
        });

        [$before, $after, $alive, $returned] = $result;
        $this->assertSame($before, $after);
        $this->assertFalse($alive);
        $this->assertSame([1], $returned);
    }

    public function testATransactionIsFinishedAsSoonAsItsSocketIsLost(): void
    {
        $result = $this->inLoop(function (): ?array {
            /** @var list<AmpWebSocketTransport> $transports */
            $transports = [];
            $options = Runtime::amp();
            $options->webSocketTransportFactory = static function (DriverContext $context, ConnectionState $state) use (&$transports): AmpWebSocketTransport {
                return $transports[] = new AmpWebSocketTransport($context, $state->endpoint);
            };

            $db = new Surreal($options);
            $this->connect($db);

            try {
                if (!$db->isFeatureSupported(Features::transactions())) {
                    return null;
                }

                $txn = $db->beginTransaction();
                $finishedWhileReconnecting = null;
                $connected = 0;
                $db->subscribe('reconnecting', static function () use (&$finishedWhileReconnecting, $txn): void {
                    $finishedWhileReconnecting ??= $txn->isFinished();
                });
                $db->subscribe('connected', static function () use (&$connected): void {
                    $connected++;
                });

                $transports[0]->close();

                $deadline = microtime(true) + 5;

                while ($connected === 0 && microtime(true) < $deadline) {
                    $this->delay(0.05);
                }

                return [$finishedWhileReconnecting, $txn->isFinished()];
            } finally {
                $db->close();
            }
        });

        if ($result === null) {
            $this->markTestSkipped('Interactive transactions need SurrealDB 3.0 or later.');
        }

        $this->assertSame([true, true], $result);
    }

    public function testACallWaitsForARenewalAlreadyInFlight(): void
    {
        $order = $this->inLoop(function (): array {
            $namespace = 'amp_renew_' . bin2hex(random_bytes(4));
            $setup = new Surreal(Runtime::amp());
            $this->connect($setup);

            try {
                // Renewal is due 60 seconds before expiry: about a second in.
                $setup->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace};"
                    . " DEFINE USER renew ON NAMESPACE PASSWORD 'secret' ROLES EDITOR DURATION FOR TOKEN 61s, FOR SESSION 1h");
            } finally {
                $setup->close();
            }

            $calls = 0;
            $renewing = false;
            $order = [];
            $provider = function () use (&$calls, &$renewing, &$order, $namespace): NamespaceAuth {
                // The first call signs in; the next is the renewal timer's,
                // which takes a while.
                if (++$calls > 1) {
                    $renewing = true;
                    $this->delay(0.5);
                    $order[] = 'renewed';
                }

                return new NamespaceAuth($namespace, 'renew', 'secret');
            };

            $db = new Surreal(Runtime::amp());
            $this->connect($db, $provider);

            try {
                $this->waitFor(static function () use (&$renewing): bool {
                    return $renewing;
                });

                // Two calls wait on the same renewal; both must resume.
                EventLoop::queue(static function () use ($db, &$order): void {
                    $db->run('RETURN 2');
                    $order[] = 'query';
                });

                $db->run('RETURN 1');
                $order[] = 'query';

                $this->waitFor(static function () use (&$order): bool {
                    return count($order) === 3;
                });

                return $order;
            } finally {
                $db->close();
            }
        });

        $this->assertSame(['renewed', 'query', 'query'], $order);
    }

    public function testARestoreWaitsForARenewalAlreadyInFlight(): void
    {
        $result = $this->inLoop(function (): ?array {
            $namespace = 'amp_restore_' . bin2hex(random_bytes(4));
            $setup = new Surreal(Runtime::amp());
            $this->connect($setup);

            try {
                if (!$setup->isFeatureSupported(Features::refreshTokens())) {
                    return null;
                }

                // Record tokens with a refresh token, due for renewal about a
                // second in. The server takes each refresh token only once.
                $setup->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db; USE DB db;"
                    . ' DEFINE ACCESS account ON DATABASE TYPE RECORD SIGNUP (CREATE user SET name = $name)'
                    . ' SIGNIN (SELECT * FROM user WHERE name = $name) WITH REFRESH DURATION FOR TOKEN 61s;'
                    . ' DEFINE TABLE user SCHEMALESS PERMISSIONS FULL;');
            } finally {
                $setup->close();
            }

            /** @var list<AmpWebSocketTransport> $transports */
            $transports = [];
            $refreshes = 0;
            $held = null;

            // The renewal's refresh is held while the socket drops, and goes
            // out on the new socket once the restore there has begun (with
            // `version`), before the restore's own refresh would.
            $before = static function (RpcRequest $request) use (&$transports, &$refreshes, &$held): void {
                if ($request->method === 'version' && $held !== null) {
                    $held->resume();
                    $held = null;
                }

                if ($request->method === 'refresh' && ++$refreshes === 1) {
                    $held = EventLoop::getSuspension();
                    $transports[0]->close();
                    $held->suspend();
                }
            };

            $options = Runtime::amp();
            $options->middleware = [new class ($before) implements MiddlewareInterface {
                public function __construct(private readonly \Closure $before) {}

                public function process(RpcRequest $request, callable $next): RpcResponse
                {
                    ($this->before)($request);

                    return $next($request);
                }
            }];
            $options->webSocketTransportFactory = static function (DriverContext $context, ConnectionState $state) use (&$transports): AmpWebSocketTransport {
                return $transports[] = new AmpWebSocketTransport($context, $state->endpoint);
            };

            $db = new Surreal($options);
            $this->connect($db);

            try {
                $db->use($namespace, 'db');
                $db->signup(new RecordAccessAuth($namespace, 'db', 'account', ['name' => 'ada']));

                $this->waitFor(static function () use (&$transports, &$refreshes, $db): bool {
                    return $refreshes >= 1 && count($transports) >= 2 && $db->status() === ConnectionStatus::Connected;
                });

                return [$db->accessToken(), $db->run('RETURN $auth.name')];
            } finally {
                $db->close();
            }
        });

        if ($result === null) {
            $this->markTestSkipped('Refresh tokens need SurrealDB 3.0 or later.');
        }

        [$token, $name] = $result;
        $this->assertNotNull($token);
        $this->assertSame(['ada'], $name);
    }

    public function testAConnectionReplacedDuringARenewalRenewsForItself(): void
    {
        $elapsed = $this->inLoop(function (): float {
            $namespace = 'amp_renew_' . bin2hex(random_bytes(4));
            $setup = new Surreal(Runtime::amp());
            $this->connect($setup);

            try {
                // Renewal is due 60 seconds before expiry: about a second in.
                $setup->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace};"
                    . " DEFINE USER renew ON NAMESPACE PASSWORD 'secret' ROLES EDITOR DURATION FOR TOKEN 61s, FOR SESSION 1h");
            } finally {
                $setup->close();
            }

            $calls = 0;
            $renewing = false;
            $slow = function () use (&$calls, &$renewing, $namespace): NamespaceAuth {
                // The first connection's renewal takes a while.
                if (++$calls > 1) {
                    $renewing = true;
                    $this->delay(3.0);
                }

                return new NamespaceAuth($namespace, 'renew', 'secret');
            };

            $db = new Surreal(Runtime::amp());
            $this->connect($db, $slow);

            try {
                $this->waitFor(static function () use (&$renewing): bool {
                    return $renewing;
                });

                // Replace the connection while that renewal is still running.
                $this->connect($db, static fn (): NamespaceAuth => new NamespaceAuth($namespace, 'renew', 'secret'));

                // Wait for the new connection's own token to fall due.
                $this->delay(1.2);
                $start = microtime(true);
                $db->run('RETURN 1');

                return microtime(true) - $start;
            } finally {
                $db->close();
            }
        });

        $this->assertLessThan(0.5, $elapsed);
    }

    public function testSigningOutDuringARenewalKeepsTheSessionSignedOut(): void
    {
        $result = $this->inLoop(function (): array {
            $namespace = 'amp_renew_' . bin2hex(random_bytes(4));
            $setup = new Surreal(Runtime::amp());
            $this->connect($setup);

            try {
                // Renewal is due 60 seconds before expiry: about a second in.
                $setup->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace};"
                    . " DEFINE USER renew ON NAMESPACE PASSWORD 'secret' ROLES EDITOR DURATION FOR TOKEN 61s, FOR SESSION 1h");
            } finally {
                $setup->close();
            }

            $calls = 0;
            $renewing = false;
            $renewed = false;
            $provider = function () use (&$calls, &$renewing, &$renewed, $namespace): NamespaceAuth {
                // The renewal timer's call takes a while.
                if (++$calls > 1) {
                    $renewing = true;
                    $this->delay(0.5);
                    $renewed = true;
                }

                return new NamespaceAuth($namespace, 'renew', 'secret');
            };

            $db = new Surreal(Runtime::amp());
            $this->connect($db, $provider);

            try {
                $this->waitFor(static function () use (&$renewing): bool {
                    return $renewing;
                });

                $db->invalidate();

                $this->waitFor(static function () use (&$renewed): bool {
                    return $renewed;
                });
                $this->delay(0.2);

                try {
                    $db->run('INFO FOR NS');
                    $refused = false;
                } catch (SurrealException) {
                    $refused = true;
                }

                return [$db->accessToken(), $refused];
            } finally {
                $db->close();
            }
        });

        $this->assertSame([null, true], $result);
    }

    public function testARenewalFailingForAReplacedConnectionIsNotReported(): void
    {
        $errors = $this->inLoop(function (): array {
            $namespace = 'amp_renew_' . bin2hex(random_bytes(4));
            $setup = new Surreal(Runtime::amp());
            $this->connect($setup);

            try {
                // Renewal is due 60 seconds before expiry: about a second in.
                $setup->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace};"
                    . " DEFINE USER renew ON NAMESPACE PASSWORD 'secret' ROLES EDITOR DURATION FOR TOKEN 61s, FOR SESSION 1h");
            } finally {
                $setup->close();
            }

            $calls = 0;
            $renewing = false;
            $failing = function () use (&$calls, &$renewing, $namespace): NamespaceAuth {
                // The first connection's renewal fails, after a while.
                if (++$calls > 1) {
                    $renewing = true;
                    $this->delay(0.5);

                    throw new \RuntimeException('old renewal failed');
                }

                return new NamespaceAuth($namespace, 'renew', 'secret');
            };

            $db = new Surreal(Runtime::amp());
            $this->connect($db, $failing);

            try {
                $this->waitFor(static function () use (&$renewing): bool {
                    return $renewing;
                });

                $this->connect($db, static fn (): NamespaceAuth => new NamespaceAuth($namespace, 'renew', 'secret'));
                $errors = [];
                $db->subscribe('error', static function (\Throwable $error) use (&$errors): void {
                    $errors[] = $error->getMessage();
                });

                $this->delay(1.0);

                return $errors;
            } finally {
                $db->close();
            }
        });

        $this->assertSame([], $errors);
    }

    public function testAFailedBackgroundRenewalIsRetriedWithoutACall(): void
    {
        $calls = $this->inLoop(function (): int {
            $namespace = 'amp_renew_' . bin2hex(random_bytes(4));
            $setup = new Surreal(Runtime::amp());
            $this->connect($setup);

            try {
                // Renewal is due 60 seconds before expiry: about a second in.
                $setup->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace};"
                    . " DEFINE USER renew ON NAMESPACE PASSWORD 'secret' ROLES EDITOR DURATION FOR TOKEN 61s, FOR SESSION 1h");
            } finally {
                $setup->close();
            }

            $calls = 0;
            $provider = static function () use (&$calls, $namespace): NamespaceAuth {
                // The renewal timer's first attempt fails.
                if (++$calls === 2) {
                    throw new \RuntimeException('provider unavailable');
                }

                return new NamespaceAuth($namespace, 'renew', 'secret');
            };

            $db = new Surreal(Runtime::amp());
            $this->connect($db, $provider);

            try {
                // No calls: only a timer can retry, five seconds on.
                $this->delay(7.5);

                return $calls;
            } finally {
                $db->close();
            }
        });

        $this->assertGreaterThanOrEqual(3, $calls);
    }

    public function testATokenCloseToExpiryIsKeptWhileStillValid(): void
    {
        $result = $this->inLoop(function (): ?array {
            $db = new Surreal(Runtime::amp());
            $this->connect($db);

            try {
                if (!$db->isFeatureSupported(Features::sessions())) {
                    return null;
                }

                $user = 'brief_' . bin2hex(random_bytes(4));
                $db->run("DEFINE USER {$user} ON ROOT PASSWORD 'secret' ROLES OWNER DURATION FOR TOKEN 30s, FOR SESSION 1h");
                $db->signin(new RootAuth($user, 'secret'));
                $token = $db->accessToken();
                $fork = $db->forkSession();

                // Renewal is due at once, and nothing can renew these tokens:
                // let the timers run, then check both are still signed in.
                $this->delay(0.2);

                return [
                    $token,
                    $db->accessToken(),
                    $fork->accessToken(),
                    $fork->run("RETURN 'ok'"),
                ];
            } finally {
                $db->close();
            }
        });

        if ($result === null) {
            $this->markTestSkipped('Sessions need SurrealDB 3.0 or later.');
        }

        [$token, $source, $fork, $ran] = $result;
        $this->assertNotNull($token);
        $this->assertSame($token, $source);
        $this->assertSame($token, $fork);
        $this->assertSame(['ok'], $ran);
    }

    public function testAKeptTokenIsSignedOutWhenItExpires(): void
    {
        $result = $this->inLoop(function (): array {
            $db = new Surreal(Runtime::amp());
            $this->connect($db);

            try {
                $user = 'fleeting_' . bin2hex(random_bytes(4));
                $db->run("DEFINE USER {$user} ON ROOT PASSWORD 'secret' ROLES OWNER DURATION FOR TOKEN 2s, FOR SESSION 1h");
                $db->signin(new RootAuth($user, 'secret'));
                $signedIn = $db->accessToken();
                $signedOut = false;
                $db->subscribe('auth', static function ($tokens) use (&$signedOut): void {
                    $signedOut = $signedOut || $tokens === null;
                });

                // Nothing can renew it, so it is kept until it expires.
                $this->delay(3.5);

                return [$signedIn, $db->accessToken(), $signedOut];
            } finally {
                $db->close();
            }
        });

        [$signedIn, $after, $signedOut] = $result;
        $this->assertNotNull($signedIn);
        $this->assertNull($after);
        $this->assertTrue($signedOut);
    }

    private function connect(Surreal $db, ?\Closure $authentication = null): void
    {
        $url = getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc';

        // Only an unreachable endpoint means "no local server": once it
        // accepts connections, any connect-time failure (handshake, decoding,
        // authentication, session restore) is a regression and must fail.
        $host = parse_url($url, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'wss' ? 443 : 80);
        $probe = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $code, $message, 1.0);

        if ($probe === false) {
            if (getenv('SURREALDB_REQUIRE_LOCAL') === '1') {
                self::fail(sprintf('Local SurrealDB test database is required but unavailable at %s: %s', $url, $message));
            }

            self::markTestSkipped(sprintf('Local SurrealDB test database is not available at %s: %s', $url, $message));
        }

        fclose($probe);

        $db->connect(
            $url,
            new ConnectOptions(
                authentication: $authentication ?? new RootAuth('root', 'root'),
                reconnect: ['retryDelay' => 0.05],
            ),
        );
    }

    /**
     * @param \Closure(): bool $condition
     */
    private function waitFor(\Closure $condition): void
    {
        $deadline = microtime(true) + 5;

        while (!$condition() && microtime(true) < $deadline) {
            $this->delay(0.02);
        }
    }

    private function delay(float $seconds): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay($seconds, static fn () => $suspension->resume());
        $suspension->suspend();
    }

    /**
     * Run `$test` in a fiber on the event loop, failing instead of hanging if
     * it does not finish within ten seconds.
     *
     * @template T
     *
     * @param \Closure(): T $test
     *
     * @return T
     */
    private function inLoop(\Closure $test): mixed
    {
        $outcome = null;
        $watchdog = EventLoop::delay(10, static function () use (&$outcome): void {
            $outcome ??= ['error', new \RuntimeException('Timed out: the event loop never finished the call.')];
            EventLoop::getDriver()->stop();
        });

        // The engine's pinger keeps a timer on the loop, so stop the loop
        // explicitly once the test fiber is done.
        EventLoop::queue(static function () use ($test, &$outcome): void {
            try {
                $outcome = ['ok', $test()];
            } catch (\Throwable $error) {
                $outcome = ['error', $error];
            }

            EventLoop::getDriver()->stop();
        });

        while ($outcome === null) {
            EventLoop::run();
        }

        EventLoop::cancel($watchdog);
        [$kind, $value] = $outcome;

        if ($kind === 'error') {
            throw $value;
        }

        return $value;
    }
}
