<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Exceptions\UnavailableFeatureException;
use SurrealDB\SDK\Exceptions\UnsupportedFeatureException;
use SurrealDB\SDK\Live\LiveAction;
use SurrealDB\SDK\Live\LiveMessage;
use SurrealDB\SDK\Live\LiveSubscription;
use SurrealDB\SDK\Live\ManagedLiveSubscription;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Query\BoundQuery;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;
use function count;

/**
 * Live queries against a real server over WebSocket, under the default
 * synchronous scheduler.
 */
final class LiveQueryTest extends TestCase
{
    public function testManagedLiveQueryDeliversChangesAndStopsWhenKilled(): void
    {
        $db = $this->connect();

        try {
            $live = $db->live(new Table('person'))->execute();

            self::assertInstanceOf(ManagedLiveSubscription::class, $live);
            self::assertTrue($live->isManaged());
            self::assertTrue($live->isAlive());
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $live->id());

            // These notifications arrive while the writes wait for their
            // replies, before anything iterates the subscription.
            $db->create(new RecordId('person', 'tobie'))->content(['name' => 'Tobie'])->execute();
            $db->update(new RecordId('person', 'tobie'))->merge(['name' => 'Tobie M'])->execute();
            $db->delete(new RecordId('person', 'tobie'))->execute();

            $messages = $this->take($live, 3);

            self::assertSame([LiveAction::Create, LiveAction::Update, LiveAction::Delete], array_map(
                static fn (LiveMessage $message): LiveAction => $message->action,
                $messages,
            ));
            self::assertSame($live->id(), $messages[0]->queryId);
            self::assertEquals(new RecordId('person', 'tobie'), $messages[0]->record);
            self::assertSame('Tobie', $messages[0]->value['name'] ?? null);
            self::assertSame('Tobie M', $messages[1]->value['name'] ?? null);

            self::assertArrayHasKey($live->id(), $db->run('INFO FOR TABLE person')[0]['lives']);

            $live->kill();

            self::assertFalse($live->isAlive());
            self::assertSame([], $db->run('INFO FOR TABLE person')[0]['lives']);
        } finally {
            $db->close();
        }
    }

    public function testFieldsWhereAndDiffShapeTheNotifications(): void
    {
        $db = $this->connect();

        try {
            $adults = $db->live('person')->fields('name')->where('age >= 18')->execute();
            $diffs = $db->live(new Table('person'))->diff()->execute();

            $db->create(new RecordId('person', 'child'))->content(['name' => 'Kid', 'age' => 9])->execute();
            $db->create(new RecordId('person', 'adult'))->content(['name' => 'Grown', 'age' => 40])->execute();

            [$adult] = $this->take($adults, 1);
            self::assertSame(['name' => 'Grown'], $adult->value);

            [$first] = $this->take($diffs, 1);
            self::assertSame(LiveAction::Create, $first->action);
            self::assertIsArray($first->value);
            self::assertSame('replace', $first->value[0]['op'] ?? null);

            $adults->kill();
            $diffs->kill();
        } finally {
            $db->close();
        }
    }

    public function testABoundConditionFiltersOn3xAndIsRefusedOn2x(): void
    {
        $db = $this->connect();

        try {
            $condition = new BoundQuery('age >= $min', ['min' => 18]);

            if (!$db->isFeatureSupported(Features::liveQueryBindings())) {
                $this->expectException(UnavailableFeatureException::class);
                $db->live('person')->where($condition)->execute();

                return;
            }

            $adults = $db->live('person')->where($condition)->execute();
            $db->create(new RecordId('person', 'child'))->content(['age' => 9])->execute();
            $db->create(new RecordId('person', 'adult'))->content(['age' => 40])->execute();

            [$adult] = $this->take($adults, 1);
            self::assertSame(40, $adult->value['age'] ?? null);

            $adults->kill();
        } finally {
            $db->close();
        }
    }

    public function testLiveOfAttachesToALiveQueryStartedInSurrealQl(): void
    {
        $db = $this->connect();

        try {
            [$id] = $db->run('LIVE SELECT * FROM person');
            $live = $db->liveOf($id);

            self::assertFalse($live->isManaged());
            self::assertSame((string) $id, $live->id());

            $db->create(new RecordId('person', 'jaime'))->content(['name' => 'Jaime'])->execute();

            [$message] = $this->take($live, 1);
            self::assertSame('Jaime', $message->value['name'] ?? null);

            $db->kill($id);
            $this->addToAssertionCount(1);
        } finally {
            $db->close();
        }
    }

    public function testResettingTheSessionEndsItsLiveQueries(): void
    {
        $db = $this->connect();

        try {
            $managed = $db->live(new Table('person'))->execute();
            [$id] = $db->run('LIVE SELECT * FROM person');
            $unmanaged = $db->liveOf($id);

            $db->reset();

            self::assertFalse($managed->isAlive());
            self::assertFalse($unmanaged->isAlive());

            // Iteration ends instead of waiting for notifications that
            // cannot come.
            foreach ([$managed, $unmanaged] as $live) {
                foreach ($live as $message) {
                    self::assertSame(LiveAction::Killed, $message->action);
                }
            }
        } finally {
            $db->close();
        }
    }

    public function testSigningOutOrInAsSomeoneElseEndsLiveQueries(): void
    {
        foreach ([
            'invalidate' => static fn (Surreal $db) => $db->invalidate(),
            'signin' => static fn (Surreal $db) => $db->signin(new RootAuth('root', 'root')),
        ] as $call => $change) {
            $db = $this->connect();

            try {
                $live = $db->live(new Table('person'))->execute();
                $change($db);

                self::assertFalse($live->isAlive(), "{$call}() should end the live query");

                foreach ($live as $message) {
                    self::assertSame(LiveAction::Killed, $message->action);
                }
            } finally {
                $db->close();
            }
        }
    }

    public function testRemovingTheTableEndsTheSubscription(): void
    {
        $db = $this->connect();
        // SurrealDB 2.x sends no KILLED notification when the table goes.
        $this->requireV3($db);

        try {
            $live = $db->live(new Table('person'))->execute();
            $db->run('REMOVE TABLE person');

            $messages = iterator_to_array($live, false);

            self::assertCount(1, $messages);
            self::assertSame(LiveAction::Killed, $messages[0]->action);
            self::assertFalse($live->isAlive());

            // Killing after the server already did is a no-op.
            $live->kill();
        } finally {
            $db->close();
        }
    }

    public function testHttpConnectionsCannotUseLiveQueries(): void
    {
        $db = new Surreal();

        try {
            $db->connect(getenv('SURREALDB_HTTP_URL') ?: 'http://127.0.0.1:8000', new ConnectOptions(
                authentication: new RootAuth('root', 'root'),
            ));
        } catch (\Throwable $error) {
            $this->unavailable($error);
        }

        $this->expectException(UnsupportedFeatureException::class);
        $db->live('person')->execute();
    }

    /**
     * @return list<LiveMessage<mixed>>
     */
    private function take(LiveSubscription $live, int $count): array
    {
        $messages = [];

        foreach ($live as $message) {
            $messages[] = $message;

            if (count($messages) === $count) {
                break;
            }
        }

        self::assertCount($count, $messages);

        return $messages;
    }

    private function requireV3(Surreal $db): void
    {
        if ((int) preg_replace('/^surrealdb-/', '', $db->version()) < 3) {
            $db->close();
            self::markTestSkipped('Needs SurrealDB 3.0 or later.');
        }
    }

    private function connect(): Surreal
    {
        $db = new Surreal();
        $namespace = 'live_' . bin2hex(random_bytes(4));

        try {
            $db->connect(getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc', new ConnectOptions(
                authentication: new RootAuth('root', 'root'),
            ));
            $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db;");
            $db->use($namespace, 'db');
            $db->run('DEFINE TABLE person SCHEMALESS PERMISSIONS FULL');
        } catch (\Throwable $error) {
            $this->unavailable($error);
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
