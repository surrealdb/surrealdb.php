<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\BoundExcluded;
use SurrealDB\SDK\Types\BoundIncluded;
use SurrealDB\SDK\Types\File;
use SurrealDB\SDK\Types\GeometryCollection;
use SurrealDB\SDK\Types\GeometryLine;
use SurrealDB\SDK\Types\GeometryMultiPoint;
use SurrealDB\SDK\Types\GeometryPoint;
use SurrealDB\SDK\Types\GeometryPolygon;
use SurrealDB\SDK\Types\Range;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\Set;
use SurrealDB\SDK\Types\Value;

/**
 * Sends every CBOR-tagged value with a tag above 23 to a real server as a
 * bound parameter, has the server compare it with the equivalent SurrealQL
 * literal, and checks the value it sends back.
 */
final class CborHighTagRoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{'http'|'ws', Value, string}>
     */
    public static function values(): iterable
    {
        $line = new GeometryLine(new GeometryPoint(0.0, 0.0), new GeometryPoint(1.0, 1.0));
        $square = new GeometryPolygon(new GeometryLine(
            new GeometryPoint(0.0, 0.0),
            new GeometryPoint(1.0, 0.0),
            new GeometryPoint(1.0, 1.0),
            new GeometryPoint(0.0, 0.0),
        ));

        $cases = [
            'range' => [new Range(new BoundIncluded(1), new BoundExcluded(10)), '1..10'],
            'open range' => [new Range(new BoundExcluded(1), null), '1>..'],
            'record id range' => [new RecordIdRange('person', new BoundIncluded(1), new BoundIncluded(100)), 'person:1..=100'],
            'set' => [new Set([1, 2, 3]), '<set>[3, 2, 1]'],
            'point' => [new GeometryPoint(1.5, 2.5), '(1.5, 2.5)'],
            'line' => [$line, '{ type: "LineString", coordinates: [[0.0, 0.0], [1.0, 1.0]] }'],
            'polygon' => [$square, '{ type: "Polygon", coordinates: [[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 0.0]]] }'],
            'multi point' => [
                new GeometryMultiPoint(new GeometryPoint(1.0, 2.0), new GeometryPoint(3.0, 4.0)),
                '{ type: "MultiPoint", coordinates: [[1.0, 2.0], [3.0, 4.0]] }',
            ],
            'collection' => [
                new GeometryCollection(new GeometryPoint(1.0, 2.0), $line),
                '{ type: "GeometryCollection", geometries: [{ type: "Point", coordinates: [1.0, 2.0] }, { type: "LineString", coordinates: [[0.0, 0.0], [1.0, 1.0]] }] }',
            ],
        ];

        foreach (['http', 'ws'] as $protocol) {
            foreach ($cases as $name => [$value, $literal]) {
                yield "{$protocol} {$name}" => [$protocol, $value, $literal];
            }
        }
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('values')]
    public function testServerReadsAndReturnsTheValue(string $protocol, Value $value, string $literal): void
    {
        $db = $this->connect($protocol);

        try {
            [$result] = $db->run("RETURN [\$given == {$literal}, \$given]", ['given' => $value]);

            self::assertIsArray($result);
            self::assertTrue($result[0], "The server did not read the value as {$literal}");
            self::assertInstanceOf($value::class, $result[1]);
            self::assertTrue($value->equals($result[1]), 'The value did not survive the round trip');
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testRecordIdRangeSelectsMatchingRecords(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            $db->run('DEFINE NAMESPACE IF NOT EXISTS cbor; USE NS cbor; DEFINE DATABASE IF NOT EXISTS cbor;');
            $db->use('cbor', 'cbor');
            $db->run('DEFINE TABLE IF NOT EXISTS item; DELETE item; FOR $i IN 1..=5 { CREATE type::record("item", $i) }');

            $selected = $db->select(new RecordIdRange('item', new BoundIncluded(2), new BoundExcluded(4)))->execute();

            self::assertIsArray($selected);
            self::assertEquals([new RecordId('item', 2), new RecordId('item', 3)], array_column($selected, 'id'));
        } finally {
            $db->close();
        }
    }

    /**
     * @param 'http'|'ws' $protocol
     */
    #[DataProvider('protocols')]
    public function testFileEncodesWhenTheServerEnablesFiles(string $protocol): void
    {
        $db = $this->connect($protocol);

        try {
            try {
                [$kind] = $db->run('RETURN type::of($file)', ['file' => new File('bucket', '/path.txt')]);
            } catch (\SurrealDB\SDK\Exceptions\ServerException $error) {
                self::markTestSkipped('The server does not have the experimental files feature enabled: ' . $error->getMessage());
            }

            self::assertSame('file', $kind);
        } finally {
            $db->close();
        }
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

        try {
            $db->connect($url, new ConnectOptions(authentication: new RootAuth('root', 'root')));
        } catch (\Throwable $error) {
            if (getenv('SURREALDB_REQUIRE_LOCAL') === '1') {
                self::fail('Local SurrealDB test database is required but unavailable: ' . $error->getMessage());
            }

            self::markTestSkipped('Local SurrealDB test database is not available: ' . $error->getMessage());
        }

        return $db;
    }
}
