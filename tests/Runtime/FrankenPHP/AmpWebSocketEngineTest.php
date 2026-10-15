<?php

namespace SurrealDB\Tests\Runtime\FrankenPHP;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectionState;
use SurrealDB\SDK\Connection\ConnectionStatus;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Runtime\Runtime;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Transport\Amp\AmpWebSocketTransport;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;

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

    private function connect(Surreal $db): void
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
            new ConnectOptions(authentication: new RootAuth('root', 'root'), reconnect: ['retryDelay' => 0.05]),
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
