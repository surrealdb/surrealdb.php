<?php

namespace SurrealDB\Tests\Runtime\OpenSwoole;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectionState;
use SurrealDB\SDK\Connection\ConnectionStatus;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Contracts\DuplexTransportInterface;
use SurrealDB\SDK\Contracts\MiddlewareInterface;
use SurrealDB\SDK\Exceptions\ConnectionUnavailableException;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Runtime\Runtime;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Transport\Swoole\SwooleWebSocketTransport;
use SurrealDB\SDK\Types\Table;
use SurrealDB\Tests\Fakes\BeforeRequest;
use SurrealDB\Tests\Fakes\DroppingTransport;
use function count;

/**
 * Reconnects under OpenSwoole, where failing a pending call resumes its
 * coroutine straight away: a socket lost while the next one restores the
 * session or replays live queries must lead to another reconnect, not end
 * the connection or its subscriptions.
 *
 * Self-skips when the openswoole extension is not installed.
 */
final class SwooleWebSocketReconnectTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('The openswoole extension is not installed.');
        }
    }

    public function testLosingTheSocketWhileRestoringTheSessionReconnectsAgain(): void
    {
        $namespace = $this->defineNamespace();

        [$events, $status, $greeting] = $this->inCoroutine(function () use ($namespace): array {
            $transports = [];
            $db = $this->connect($transports, static function (DroppingTransport $replacement): void {
                // The first reply on the new socket answers a restore request.
                $replacement->dropOnNextReply = true;
            });

            try {
                $db->use($namespace, 'db');
                $db->let('greeting', 'hello');
                $events = $this->record($db);

                $transports[0]->close();
                $this->waitUntil(static function () use (&$transports, $db): bool {
                    return count($transports) >= 3 && $db->status() === ConnectionStatus::Connected;
                });

                return [$events->getArrayCopy(), $db->status(), $db->run('RETURN $greeting')];
            } finally {
                $db->close();
            }
        });

        self::assertSame(['reconnecting', 'reconnecting', 'connected'], $events);
        self::assertSame(ConnectionStatus::Connected, $status);
        self::assertSame(['hello'], $greeting);
    }

    public function testLosingTheSocketWhileReplayingKeepsTheLiveQuery(): void
    {
        $namespace = $this->defineNamespace();

        [$events, $alive] = $this->inCoroutine(function () use ($namespace): array {
            $transports = [];
            $db = $this->connect($transports, static function (DroppingTransport $replacement): void {
                $replacement->dropAfterSending = 'LIVE SELECT';
            });

            try {
                $db->use($namespace, 'db');
                $live = $db->live(new Table('person'))->execute();
                $events = $this->record($db);

                $transports[0]->close();
                $this->waitUntil(static function () use (&$transports, $db): bool {
                    return count($transports) >= 3 && $db->status() === ConnectionStatus::Connected;
                });

                return [$events->getArrayCopy(), $live->isAlive()];
            } finally {
                $db->close();
            }
        });

        self::assertSame(['reconnecting', 'reconnecting', 'connected'], $events);
        self::assertTrue($alive);
    }

    public function testACallFromAnotherCoroutineDoesNotRunOnASessionStillBeingRestored(): void
    {
        $namespace = $this->defineNamespace();

        $result = $this->inCoroutine(function () use ($namespace): array {
            $transports = [];
            /** @var (\Closure(): void)|null $other */
            $other = null;

            // The restore on the new socket is held just before it sets the
            // session's variable again, while another coroutine makes a call.
            $hold = new BeforeRequest(static function (RpcRequest $request) use (&$transports, &$other): void {
                if ($request->method === 'let' && count($transports) === 2 && $other !== null) {
                    $call = $other;
                    $other = null;
                    \OpenSwoole\Coroutine::create($call);
                    \OpenSwoole\Coroutine::usleep(50_000);
                }
            });
            $db = $this->connect($transports, static function (): void {}, [$hold]);

            try {
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
                $this->waitUntil(static function () use (&$transports, $db): bool {
                    return count($transports) >= 2 && $db->status() === ConnectionStatus::Connected;
                });

                return [$seen, $db->run('RETURN $greeting')];
            } finally {
                $db->close();
            }
        });

        // Refused, rather than answered with NONE: what the variable is on
        // the new socket until the restore sets it.
        self::assertSame([[ConnectionUnavailableException::class], ['hello']], $result);
    }

    public function testAConsumerResumedByAReconnectCanMakeACallStraightAway(): void
    {
        $namespace = $this->defineNamespace();

        $result = $this->inCoroutine(function () use ($namespace): array {
            $transports = [];
            $replays = 0;

            // Between the replays of the two live queries a record is created
            // (over another connection), so a notification is waiting for the
            // first query's consumer when the reconnect resumes it.
            $hold = new BeforeRequest(function (RpcRequest $request) use (&$transports, &$replays, $namespace): void {
                $replay = $request->method === 'query' && str_starts_with((string) ($request->params[0] ?? ''), 'LIVE SELECT');

                if ($replay && count($transports) === 2 && ++$replays === 2) {
                    $this->createPerson($namespace);
                    \OpenSwoole\Coroutine::usleep(50_000);
                }
            });
            $db = $this->connect($transports, static function (): void {}, [$hold], new RootAuth('root', 'root'));

            try {
                $db->use($namespace, 'db');
                $first = $db->live(new Table('person'))->execute();
                $second = $db->live(new Table('person'))->execute();
                $outcome = null;

                // Reads the first query across the reconnect, and makes a
                // call for the first change it sees.
                \OpenSwoole\Coroutine::create(static function () use ($db, $first, &$outcome): void {
                    foreach ($first as $message) {
                        try {
                            $outcome = $db->run('RETURN 1');
                        } catch (\Throwable $error) {
                            $outcome = $error::class;
                        }

                        break;
                    }
                });

                $transports[0]->close();
                $this->waitUntil(static function () use (&$outcome): bool {
                    return $outcome !== null;
                });

                return [$outcome, $second->isAlive()];
            } finally {
                $db->close();
            }
        });

        self::assertSame([[1], true], $result);
    }

    public function testClosingLetsTheCoroutineRuntimeFinish(): void
    {
        $this->defineNamespace();

        $leftover = $this->inCoroutine(function (): array {
            // Signed in, so a token renewal is scheduled, and pinging.
            $db = new Surreal(Runtime::swoole(enableHooks: false));
            $db->connect($this->url(), new ConnectOptions(authentication: new RootAuth('root', 'root')));
            $db->run('RETURN 1');
            $db->close();

            // Let the read loop see the closed socket.
            \OpenSwoole\Coroutine::usleep(100_000);

            $self = \OpenSwoole\Coroutine::getCid();
            $coroutines = array_values(array_filter(
                iterator_to_array(\OpenSwoole\Coroutine::list(), false),
                static fn (int $cid): bool => $cid !== $self,
            ));
            $timers = iterator_to_array(\OpenSwoole\Timer::list(), false);

            // Anything left would keep Coroutine::run() from returning: stop
            // it, so a failure here does not hang the suite as well.
            foreach ($coroutines as $cid) {
                \OpenSwoole\Coroutine::cancel($cid);
            }

            return ['coroutines' => count($coroutines), 'timers' => count($timers)];
        });

        self::assertSame(['coroutines' => 0, 'timers' => 0], $leftover);
    }

    /**
     * Connect as a guest (unless `$authentication` is given), without the
     * pinger, so that nothing is left for Coroutine::run() to wait for after
     * close() however the reconnect went.
     *
     * @param list<DroppingTransport> $transports
     * @param \Closure(DroppingTransport): void $armReplacement called with the first replacement socket
     * @param list<MiddlewareInterface> $middleware
     */
    private function connect(array &$transports, \Closure $armReplacement, array $middleware = [], ?RootAuth $authentication = null): Surreal
    {
        $options = Runtime::swoole(new DriverOptions(middleware: $middleware, pingInterval: 0), enableHooks: false);
        $options->webSocketTransportFactory = static function (DriverContext $context, ConnectionState $state) use (&$transports, $armReplacement): DuplexTransportInterface {
            $transport = new DroppingTransport(new SwooleWebSocketTransport($context, $state->endpoint));

            if (count($transports) === 1) {
                $armReplacement($transport);
            }

            return $transports[] = $transport;
        };

        $db = new Surreal($options);
        $db->connect($this->url(), new ConnectOptions(
            authentication: $authentication,
            reconnect: ['retryDelay' => 0.01, 'attempts' => 5],
        ));

        return $db;
    }

    /**
     * @return \ArrayObject<int, string>
     */
    private function record(Surreal $db): \ArrayObject
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();

        foreach (['reconnecting', 'connected', 'disconnected', 'error'] as $event) {
            $db->subscribe($event, static function () use ($events, $event): void {
                $events[] = $event;
            });
        }

        return $events;
    }

    /**
     * @param \Closure(): bool $condition
     */
    private function waitUntil(\Closure $condition): void
    {
        $deadline = microtime(true) + 5;

        while (!$condition() && microtime(true) < $deadline) {
            \OpenSwoole\Coroutine::usleep(20_000);
        }
    }

    /**
     * A namespace with a table guests can watch, defined over a separate,
     * synchronous root connection. Also where an unavailable server skips
     * (or, in CI, fails) the test.
     */
    private function defineNamespace(): string
    {
        $namespace = 'swoole_' . bin2hex(random_bytes(4));
        $db = new Surreal();

        try {
            $db->connect($this->url(), new ConnectOptions(authentication: new RootAuth('root', 'root')));
            $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db; USE DB db; DEFINE TABLE person SCHEMALESS PERMISSIONS FULL;");
        } catch (\Throwable $error) {
            if (getenv('SURREALDB_REQUIRE_LOCAL') === '1') {
                self::fail('Local SurrealDB test database is required but unavailable: ' . $error->getMessage());
            }

            self::markTestSkipped('Local SurrealDB test database is not available: ' . $error->getMessage());
        } finally {
            $db->close();
        }

        return $namespace;
    }

    /**
     * Create a record in the namespace's table over a separate, synchronous
     * root connection.
     */
    private function createPerson(string $namespace): void
    {
        $db = new Surreal();

        try {
            $db->connect($this->url(), new ConnectOptions(namespace: $namespace, database: 'db', authentication: new RootAuth('root', 'root')));
            $db->run('CREATE person SET name = "p"');
        } finally {
            $db->close();
        }
    }

    /**
     * Run a test body in a coroutine and rethrow whatever it threw.
     *
     * @template T
     *
     * @param \Closure(): T $body
     *
     * @return T
     */
    private function inCoroutine(\Closure $body): mixed
    {
        $result = null;
        $error = null;

        \OpenSwoole\Coroutine::run(static function () use ($body, &$result, &$error): void {
            try {
                $result = $body();
            } catch (\Throwable $caught) {
                $error = $caught;
            }
        });

        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    private function url(): string
    {
        return getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc';
    }
}
