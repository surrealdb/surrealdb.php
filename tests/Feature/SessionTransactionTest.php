<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Exceptions\ConfigurationException;
use SurrealDB\SDK\Exceptions\InvalidSessionException;
use SurrealDB\SDK\Exceptions\ServerException;
use SurrealDB\SDK\Exceptions\TransactionFinishedException;
use SurrealDB\SDK\Exceptions\UnsupportedFeatureException;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\SurrealSession;
use SurrealDB\SDK\SurrealTransaction;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;

/**
 * Session and transaction objects against a real server over WebSocket.
 */
final class SessionTransactionTest extends TestCase
{
    private string $namespace;

    public function testNewSessionsAreIsolatedFromTheDefaultSession(): void
    {
        $db = $this->connect();

        try {
            $session = $db->newSession();

            self::assertInstanceOf(SurrealSession::class, $session);
            self::assertNotNull($session->id());
            self::assertTrue($session->isValid());
            self::assertContains($session->id(), $db->sessions());

            // Authenticated by the connection's auth provider, but with no
            // namespace, database or variables of its own.
            self::assertNotNull($session->accessToken());
            self::assertNull($session->namespace());

            $session->use($this->namespace, 'other');
            $session->let('name', 'session');
            $db->let('name', 'root');

            self::assertSame($this->namespace, $session->namespace());
            self::assertSame('other', $session->database());
            self::assertSame('db', $db->database());
            self::assertSame(['session'], $session->run('RETURN $name'));
            self::assertSame(['root'], $db->run('RETURN $name'));

            // Builders target the session's database.
            $session->create(new RecordId('person', 'in_session'))->content(['name' => 'S'])->execute();

            self::assertCount(1, $session->select(new Table('person'))->execute());
            self::assertSame([], $db->select(new Table('person'))->execute());

            $session->closeSession();

            self::assertFalse($session->isValid());
            self::assertNotContains($session->id(), $db->sessions());
        } finally {
            $db->close();
        }
    }

    public function testForkCopiesNamespaceDatabaseVariablesAndAuthentication(): void
    {
        $db = $this->connect();

        try {
            $db->let('greeting', 'hello');
            $fork = $db->forkSession();

            self::assertNotNull($fork->id());
            self::assertSame($this->namespace, $fork->namespace());
            self::assertSame('db', $fork->database());
            self::assertSame($db->accessToken(), $fork->accessToken());
            self::assertSame(['hello'], $fork->run('RETURN $greeting'));

            // Diverges from its source afterwards.
            $fork->let('greeting', 'bye');
            self::assertSame(['hello'], $db->run('RETURN $greeting'));

            // A fork of a fork.
            $nested = $fork->forkSession();
            self::assertSame(['bye'], $nested->run('RETURN $greeting'));

            $nested->closeSession();
            $fork->closeSession();
        } finally {
            $db->close();
        }
    }

    public function testAForkKeepsASourceTokenCloseToExpiry(): void
    {
        $db = $this->connect();

        try {
            $user = 'brief_' . bin2hex(random_bytes(4));
            $db->run("DEFINE USER {$user} ON ROOT PASSWORD 'secret' ROLES OWNER DURATION FOR TOKEN 30s, FOR SESSION 1h");
            $db->signin(new RootAuth($user, 'secret'));
            $token = $db->accessToken();

            $fork = $db->forkSession();

            // Too close to expiry for a reconnect to reuse, but still valid.
            self::assertSame($token, $fork->accessToken());
            self::assertSame(['ok'], $fork->run("RETURN 'ok'"));
            self::assertNotEmpty($fork->run('INFO FOR ROOT'));

            $fork->closeSession();
        } finally {
            $db->close();
        }
    }

    public function testASessionWhoseRestoreFailsIsNotLeft(): void
    {
        $db = $this->connect(authentication: static function (?string $session): RootAuth {
            if ($session !== null) {
                throw new \RuntimeException('No credentials for new sessions.');
            }

            return new RootAuth('root', 'root');
        });

        try {
            $before = $db->sessions();

            try {
                $db->newSession();
                self::fail('newSession() should fail when its authentication does');
            } catch (\RuntimeException $error) {
                self::assertSame('No credentials for new sessions.', $error->getMessage());
            }

            self::assertSame($before, $db->sessions());
        } finally {
            $db->close();
        }
    }

    public function testTheDefaultSessionCannotBeClosed(): void
    {
        $db = $this->connect();

        try {
            $this->expectException(InvalidSessionException::class);
            $db->closeSession();
        } finally {
            $db->close();
        }
    }

    public function testCommittedTransactionChangesBecomeVisible(): void
    {
        $db = $this->connect();

        try {
            $txn = $db->beginTransaction();

            self::assertInstanceOf(SurrealTransaction::class, $txn);
            self::assertNull($txn->sessionId());

            $txn->create(new RecordId('person', 'one'))->content(['name' => 'One'])->execute();
            $txn->run('CREATE person:two SET name = $name', ['name' => 'Two']);

            // Uncommitted writes are visible inside the transaction only.
            self::assertCount(2, $txn->select(new Table('person'))->execute());
            self::assertSame([], $db->select(new Table('person'))->execute());

            $txn->commit();

            self::assertTrue($txn->isFinished());
            self::assertCount(2, $db->select(new Table('person'))->execute());
        } finally {
            $db->close();
        }
    }

    public function testCancelledTransactionChangesAreDiscarded(): void
    {
        $db = $this->connect();

        try {
            $session = $db->newSession();
            $session->use($this->namespace, 'db');
            $txn = $session->beginTransaction();

            self::assertSame($session->id(), $txn->sessionId());

            $txn->create(new RecordId('person', 'gone'))->content(['name' => 'Gone'])->execute();
            $txn->cancel();

            self::assertSame([], $session->select(new Table('person'))->execute());

            $this->expectException(TransactionFinishedException::class);
            $txn->select(new Table('person'))->execute();
        } finally {
            $db->close();
        }
    }

    public function testATransactionTheServerRejectsIsFinished(): void
    {
        $db = $this->connect();

        try {
            $txn = $db->beginTransaction();
            // End it behind the handle's back, so the server no longer knows it.
            $db->connection()->cancel($txn->id());

            try {
                $txn->commit();
                self::fail('Committing an unknown transaction should fail.');
            } catch (ServerException) {
            }

            self::assertTrue($txn->isFinished());

            // The usual cleanup reports the transaction as finished rather
            // than raising a second server error.
            $this->expectException(TransactionFinishedException::class);
            $txn->cancel();
        } finally {
            $db->close();
        }
    }

    public function testSessionsAndTransactionsNeedWebSocket(): void
    {
        $db = new Surreal();

        try {
            $db->connect(getenv('SURREALDB_HTTP_URL') ?: 'http://127.0.0.1:8000', new ConnectOptions(
                authentication: new RootAuth('root', 'root'),
            ));
        } catch (\Throwable $error) {
            $this->unavailable($error);
        }

        try {
            try {
                $db->newSession();
                self::fail('newSession() should require WebSocket');
            } catch (UnsupportedFeatureException) {
            }

            $this->expectException(UnsupportedFeatureException::class);
            $db->beginTransaction();
        } finally {
            $db->close();
        }
    }

    public function testTransactionsRefuseTheJsonFormat(): void
    {
        $db = $this->connect(new DriverOptions(format: CodecEnum::JSON));

        try {
            $this->expectException(ConfigurationException::class);
            $db->beginTransaction();
        } finally {
            $db->close();
        }
    }

    /**
     * @param (\Closure(?string): RootAuth)|null $authentication
     */
    private function connect(?DriverOptions $options = null, ?\Closure $authentication = null): Surreal
    {
        $db = new Surreal($options);
        $this->namespace = 'sessions_' . bin2hex(random_bytes(4));

        try {
            $db->connect(getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc', new ConnectOptions(
                authentication: $authentication ?? new RootAuth('root', 'root'),
            ));
            $db->run("DEFINE NAMESPACE {$this->namespace}; USE NS {$this->namespace}; DEFINE DATABASE db; DEFINE DATABASE other;");
            $db->use($this->namespace, 'db');
            $db->run('DEFINE TABLE person SCHEMALESS PERMISSIONS FULL');
        } catch (\Throwable $error) {
            $this->unavailable($error);
        }

        if (!$db->isFeatureSupported(Features::sessions())) {
            $db->close();
            self::markTestSkipped('Sessions and transactions need SurrealDB 3.0 or later.');
        }

        return $db;
    }

    private function unavailable(\Throwable $error): never
    {
        if (getenv('SURREALDB_REQUIRE_LOCAL') === '1') {
            self::fail('Local SurrealDB test database is required but unavailable: ' . $error->getMessage());
        }

        self::markTestSkipped('Local SurrealDB test database is not available: ' . $error->getMessage());
    }
}
