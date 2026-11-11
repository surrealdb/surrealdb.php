<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\Decimal;
use SurrealDB\SDK\Types\Duration;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Set;
use SurrealDB\SDK\Types\StringRecordId;

/**
 * Value types sent to and read back from a real server, over HTTP and
 * WebSocket.
 */
final class TypesRoundTripTest extends TestCase
{
    /**
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testServerDurationsLongerThan292YearsDecode(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            [$durations] = $db->run('RETURN [300y, 600y, 10000000000000000000ns, 292471208677y27w6d15h30m7s999ms999us999ns]');

            self::assertIsArray($durations);
            self::assertContainsOnlyInstancesOf(Duration::class, $durations);
            self::assertSame(
                ['300y', '600y', '317y5w17h46m40s', '292471208677y27w6d15h30m7s999ms999us999ns'],
                array_map(strval(...), $durations),
            );
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testDurationsLongerThan292YearsReachTheServer(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            [$result] = $db->run('RETURN [$d == 300y, <string> $d, $d]', ['d' => Duration::years(300)]);

            self::assertIsArray($result);
            self::assertTrue($result[0], 'The server did not read the duration as 300y');
            self::assertSame('300y', $result[1]);
            self::assertTrue(Duration::years(300)->equals($result[2]));
        } finally {
            $db->close();
        }
    }

    /**
     * The server holds `t:1` and `t:"1"` as two records, and sorts the keys
     * of an object id: each id read back must equal only the one written.
     *
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testEachRecordIdReadBackEqualsOnlyTheOneWritten(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $written = [
                new RecordId('t', 1),
                new RecordId('t', '1'),
                new RecordId('t', (object) ['b' => 2, 'a' => 1]),
                new RecordId('t', [1.5, 'x']),
            ];

            $db->run('DEFINE TABLE t SCHEMALESS PERMISSIONS FULL');

            foreach ($written as $id) {
                $db->run('CREATE $id', ['id' => $id]);
            }

            [$read] = $db->run('SELECT VALUE id FROM t');

            self::assertIsArray($read);
            self::assertCount(4, $read);

            foreach ($written as $index => $id) {
                $matches = array_filter($read, static fn (mixed $readId): bool => $id->equals($readId));

                self::assertCount(1, $matches, "Id {$index} should equal exactly one id read back");
            }
        } finally {
            $db->close();
        }
    }

    /**
     * Comparing every member with every other took 12 s for these 20,000
     * record ids. SurrealDB 2.x has no sets, and returns an array.
     *
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testALargeSetOfRecordIdsDecodesInLinearTime(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $ids = array_map(static fn (int $i): RecordId => new RecordId('t', $i), range(1, 20_000));

            $started = microtime(true);
            [$set] = $db->run('RETURN <set> $ids', ['ids' => $ids]);
            $seconds = microtime(true) - $started;

            $members = $set instanceof Set ? $set->values : $set;

            self::assertIsArray($members);
            self::assertCount(20_000, $members);
            self::assertTrue($ids[0]->equals($members[0]));
            self::assertLessThan(3.0, $seconds, "Sending and decoding 20,000 record ids took {$seconds} s");
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testDecimalsKeepEveryDigitBothWays(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            [$result] = $db->run(
                'RETURN [$float == 1.2345678901234567dec, $zeros == 0dec, 0.0000000000000000000000000001dec, -79228162514264337593543950335dec]',
                ['float' => new Decimal(1.2345678901234567), 'zeros' => new Decimal('0.' . str_repeat('0', 30))],
            );

            self::assertIsArray($result);
            self::assertTrue($result[0], 'The server did not read the float as 1.2345678901234567');
            self::assertTrue($result[1], 'The server did not read the zeros as 0');
            self::assertInstanceOf(Decimal::class, $result[2]);
            self::assertSame('0.0000000000000000000000000001', (string) $result[2]);
            self::assertInstanceOf(Decimal::class, $result[3]);
            self::assertSame('-79228162514264337593543950335', (string) $result[3]);
        } finally {
            $db->close();
        }
    }

    /**
     * @return iterable<string, array{RecordId<string>}>
     */
    public static function recordIdsAsText(): iterable
    {
        yield 'a whole float' => [new RecordId('t', [1.0, -0.0, -100.0])];
        yield 'every digit of a float' => [new RecordId('t', [1.2345678901234567, 123456789012345678.0])];
        yield 'plain and scientific notation' => [new RecordId('t', [0.000001, 1e-7, -2.5e-10, 1e20, 1e21])];
        yield 'the extremes' => [new RecordId('t', [5e-324, 1.7976931348623157E308])];
        yield 'infinity' => [new RecordId('t', [INF, -INF])];
        yield 'an object as stdClass' => [new RecordId('t', (object) ['b' => 1.5, 'a' => [(object) ['x' => 1]]])];
        yield 'an empty object' => [new RecordId('t', new \stdClass())];
    }

    /**
     * A record id's text, read by the server both inline in a query and as
     * a string record id, names the same record as the id sent as CBOR, and
     * its floats are still floats.
     *
     * @param RecordId<string> $id
     */
    #[DataProvider('recordIdsAsText')]
    public function testRecordIdTextReadsBackAsTheSameId(RecordId $id): void
    {
        $db = $this->connect('http');

        try {
            [$read] = $db->run(
                "RETURN [{$id->escape()} == \$id, \$text == \$id, record::id({$id->escape()}), record::id(\$text)]",
                ['id' => $id, 'text' => new StringRecordId($id)],
            );

            self::assertIsArray($read);
            self::assertTrue($read[0], 'The id read inline names another record');
            self::assertTrue($read[1], 'The id read as a string record id names another record');
            self::assertSame(self::types($id->id), self::types($read[2]), 'The id read inline holds other types');
            self::assertSame(self::types($id->id), self::types($read[3]), 'The id read as text holds other types');
        } finally {
            $db->close();
        }
    }

    public function testRecordIdTextHoldingNanReadsBackAsNan(): void
    {
        $db = $this->connect('http');

        try {
            $id = new RecordId('t', [NAN]);
            [$read] = $db->run(
                "RETURN [record::id({$id->escape()}), record::id(\$text)]",
                ['text' => new StringRecordId($id)],
            );

            self::assertIsArray($read);
            self::assertIsFloat($read[0][0]);
            self::assertNan($read[0][0]);
            self::assertIsFloat($read[1][0]);
            self::assertNan($read[1][0]);
        } finally {
            $db->close();
        }
    }

    /**
     * The type of every scalar in a value, so 1 and 1.0 differ, with object
     * keys sorted as the server sorts them.
     *
     * @return array<mixed>|string
     */
    private static function types(mixed $value): array|string
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return get_debug_type($value);
        }

        ksort($value);

        return array_map(self::types(...), $value);
    }

    /**
     * @return iterable<string, array{'http'|'ws'}>
     */
    public static function protocols(): iterable
    {
        yield 'HTTP' => ['http'];
        yield 'WebSocket' => ['ws'];
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    private function connect(string $protocol): Surreal
    {
        $url = match ($protocol) {
            'http' => getenv('SURREALDB_HTTP_URL') ?: 'http://127.0.0.1:8000',
            'ws' => getenv('SURREALDB_WS_URL') ?: 'ws://127.0.0.1:8000/rpc',
        };

        $db = new Surreal();
        $namespace = 'types_' . bin2hex(random_bytes(4));

        try {
            $db->connect($url, new ConnectOptions(
                authentication: new RootAuth('root', 'root'),
            ));
            $db->run("DEFINE NAMESPACE {$namespace}; USE NS {$namespace}; DEFINE DATABASE db;");
            $db->use($namespace, 'db');
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
