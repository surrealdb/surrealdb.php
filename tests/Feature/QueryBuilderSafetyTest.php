<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Enum\Output;
use SurrealDB\SDK\Exceptions\ExpressionException;
use SurrealDB\SDK\Exceptions\ServerException;
use SurrealDB\SDK\Live\LiveMessage;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\BoundExcluded;
use SurrealDB\SDK\Types\BoundIncluded;
use SurrealDB\SDK\Types\None;
use SurrealDB\SDK\Types\Range;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;

/**
 * Builder calls that SurrealDB used to read as something else, against a
 * real server over WebSocket and HTTP.
 *
 * Each test starts with item:1 (n 1, tenant a), item:2 (n 2, tenant b),
 * item:3 (n 3, tenant a) and person:1 to person:4.
 */
final class QueryBuilderSafetyTest extends TestCase
{
    /**
     * @return iterable<string, array{'ws'|'http'}>
     */
    public static function protocols(): iterable
    {
        yield 'websocket' => ['ws'];
        yield 'http' => ['http'];
    }

    /**
     * @param 'ws'|'http' $protocol
     */
    #[DataProvider('protocols')]
    public function testALineCommentInAConditionDoesNotHideTheClausesAfterIt(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $items = $db->select('item')->where('n >= 2 -- the big ones')->limit(1)->execute();

            self::assertSame(['item:2'], $this->ids($items));
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'ws'|'http' $protocol
     */
    #[DataProvider('protocols')]
    public function testASemicolonInAConditionDoesNotStartAnotherStatement(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            try {
                $db->select('item')->where('n >= 2; DELETE item')->execute();
                self::fail('The condition should be refused.');
            } catch (ServerException) {
                // The server refuses it as a syntax error.
            }

            self::assertSame(['item:1', 'item:2', 'item:3'], $this->ids($db->select('item')->execute()));
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'ws'|'http' $protocol
     */
    #[DataProvider('protocols')]
    public function testEveryWhereMustHold(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $selected = $db->select('item')->where('tenant = "a"')->where('n >= 2')->execute();
            $updated = $db->update(new Table('item'))->merge(['seen' => true])->where('tenant = "a"')->where('n >= 2')->execute();
            $upserted = $db->upsert(new Table('item'))->merge(['kept' => true])->where('tenant = "a"')->where('n >= 2')->execute();

            self::assertSame(['item:3'], $this->ids($selected));
            self::assertSame(['item:3'], $this->ids($updated));
            self::assertSame(['item:3'], $this->ids($upserted));
            self::assertSame(['item:3'], $this->ids($db->select('item')->where('seen = true')->execute()));
        } finally {
            $db->close();
        }
    }

    public function testEveryWhereOfALiveSelectMustHold(): void
    {
        $db = $this->connect('ws');

        try {
            $live = $db->live('item')->where('tenant = "a"')->where('n >= 2 -- the big ones')->execute();

            $db->create(new RecordId('item', 4))->content(['n' => 5, 'tenant' => 'b'])->execute();
            $db->create(new RecordId('item', 5))->content(['n' => 1, 'tenant' => 'a'])->execute();
            $db->create(new RecordId('item', 6))->content(['n' => 7, 'tenant' => 'a'])->execute();

            foreach ($live as $message) {
                self::assertInstanceOf(LiveMessage::class, $message);
                self::assertEquals(new RecordId('item', 6), $message->record);

                break;
            }

            $live->kill();
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'ws'|'http' $protocol
     */
    #[DataProvider('protocols')]
    public function testAStatementKeywordIsNotCalledAsAFunction(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            foreach (['DELETE', 'UPDATE', 'CREATE', 'UPSERT', 'RETURN', '::'] as $name) {
                try {
                    $db->call($name, null, [new Table('item')])->execute();
                    self::fail("{$name} should be refused as a function name.");
                } catch (ExpressionException $error) {
                    self::assertStringContainsString('Invalid function name', $error->getMessage());
                }
            }

            try {
                $db->call('fn::greet', '1..0')->execute();
                self::fail('1..0 should be refused as a function version.');
            } catch (ExpressionException $error) {
                self::assertStringContainsString('Invalid function version', $error->getMessage());
            }

            self::assertSame(['item:1', 'item:2', 'item:3'], $this->ids($db->select('item')->execute()));
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'ws'|'http' $protocol
     */
    #[DataProvider('protocols')]
    public function testBuiltInAndNamespacedFunctionsAreStillCalled(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            self::assertSame(3, $db->call('count', args: [[1, 2, 3]])->execute());
            self::assertFalse($db->call('not', args: [true])->execute());
            self::assertIsFloat($db->call('rand')->execute());
            self::assertSame(3, $db->call('string::len', args: ['abc'])->execute());
            self::assertSame(3, $db->call('math::max', args: [[1, 3, 2]])->execute());
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'ws'|'http' $protocol
     */
    #[DataProvider('protocols')]
    public function testARecordIdWhoseIdIsARangeTargetsEveryRecordInIt(string $protocol): void
    {
        $db = $this->connect($protocol);
        $range = new RecordId('person', new Range(new BoundIncluded(1), new BoundExcluded(3)));

        try {
            self::assertSame(['person:1', 'person:2'], $this->ids($db->select($range)->execute()));
            self::assertSame(['person:1', 'person:2'], $this->ids($db->update($range)->merge(['seen' => true])->execute()));
            self::assertSame(['person:1', 'person:2'], $this->ids($db->upsert($range)->merge(['kept' => true])->execute()));
            self::assertSame(['person:1', 'person:2'], $this->ids($db->delete($range)->execute()));
            self::assertSame(['person:3', 'person:4'], $this->ids($db->select('person')->execute()));
        } finally {
            $db->close();
        }
    }

    /**
     * SurrealDB 2.6 refused `r"person:1..3"` as a parse error, while 3.3 and
     * 4.0 read it as `person:1` alone, so a delete removed that one record.
     *
     * @param 'ws'|'http' $protocol
     */
    #[DataProvider('protocols')]
    public function testARangeWrittenAsTextIsRefused(string $protocol): void
    {
        $db = $this->connect($protocol);
        $range = new StringRecordId('person:1..3');

        try {
            foreach ([$db->select($range), $db->update($range)->merge(['seen' => true]), $db->delete($range)] as $query) {
                try {
                    $query->execute();
                    self::fail('A range written as text should be refused.');
                } catch (ExpressionException $error) {
                    self::assertStringContainsString('RecordIdRange', $error->getMessage());
                }
            }

            self::assertSame(['person:1', 'person:2', 'person:3', 'person:4'], $this->ids($db->select('person')->execute()));
            self::assertSame([], $db->select('person')->where('seen = true')->execute());
        } finally {
            $db->close();
        }
    }

    /**
     * SurrealDB 2.x refuses `ONLY` with `RETURN NONE`; 3.x and later return
     * NONE. The builders now give NONE on every version.
     *
     * @param 'ws'|'http' $protocol
     */
    #[DataProvider('protocols')]
    public function testReturnNoneOnOneRecordGivesNone(string $protocol): void
    {
        $db = $this->connect($protocol);
        $person = static fn (int $id): RecordId => new RecordId('person', $id);

        try {
            self::assertSame(None::instance(), $db->create($person(9))->content(['name' => 'new'])->output(Output::NONE)->execute());
            self::assertSame(None::instance(), $db->update($person(1))->merge(['seen' => true])->output(Output::NONE)->execute());
            self::assertSame(None::instance(), $db->upsert($person(2))->merge(['kept' => true])->output(Output::NONE)->execute());
            self::assertSame(None::instance(), $db->delete($person(3))->output(Output::NONE)->execute());
            self::assertSame(None::instance(), $db->relate($person(1), new Table('likes'), $person(2))->output(Output::NONE)->execute());
            self::assertNull($db->update($person(4))->merge(['seen' => true])->output(Output::NONE)->json()->execute());

            self::assertSame(['person:1', 'person:4'], $this->ids($db->select('person')->where('seen = true')->execute()));
            self::assertSame(['person:2'], $this->ids($db->select('person')->where('kept = true')->execute()));
            self::assertSame(['person:1', 'person:2', 'person:4', 'person:9'], $this->ids($db->select('person')->execute()));
            self::assertCount(1, $this->ids($db->select('likes')->execute()));
        } finally {
            $db->close();
        }
    }

    /**
     * A single-record DIFF keeps `ONLY`, which every version takes, and
     * gives the whole patch list.
     *
     * @param 'ws'|'http' $protocol
     */
    #[DataProvider('protocols')]
    public function testReturnDiffOnOneRecordGivesThePatchList(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $diff = $db->update(new RecordId('person', 1))->merge(['a' => 1])->output(Output::DIFF)->execute();

            self::assertSame([['op' => 'add', 'path' => '/a', 'value' => 1]], $diff);
        } finally {
            $db->close();
        }
    }

    /**
     * The ids of the records in a result, as text.
     *
     * @return list<string>
     */
    private function ids(mixed $records): array
    {
        self::assertIsArray($records);

        $ids = [];

        foreach ($records as $record) {
            self::assertIsArray($record);
            self::assertInstanceOf(RecordId::class, $record['id'] ?? null);
            $ids[] = (string) $record['id'];
        }

        sort($ids);

        return $ids;
    }

    /**
     * @param 'ws'|'http' $protocol
     */
    private function connect(string $protocol): Surreal
    {
        $db = new Surreal();
        $namespace = 'safety_' . bin2hex(random_bytes(4));
        $url = $protocol === 'ws'
            ? (getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc')
            : (getenv('SURREALDB_HTTP_URL') ?: 'http://127.0.0.1:8000');

        try {
            $db->connect($url, new ConnectOptions(
                authentication: new RootAuth('root', 'root'),
            ));
            $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db;");
            $db->use($namespace, 'db');
            $db->run(<<<'SURQL'
                DEFINE TABLE item SCHEMALESS PERMISSIONS FULL;
                DEFINE TABLE person SCHEMALESS PERMISSIONS FULL;
                DEFINE TABLE likes SCHEMALESS PERMISSIONS FULL;
                CREATE item:1 SET n = 1, tenant = "a";
                CREATE item:2 SET n = 2, tenant = "b";
                CREATE item:3 SET n = 3, tenant = "a";
                CREATE person:1, person:2, person:3, person:4;
                SURQL);
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
