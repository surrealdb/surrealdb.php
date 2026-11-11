<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\Codec\ValueMapper;
use SurrealDB\Types\BoundExcluded;
use SurrealDB\Types\BoundIncluded;
use SurrealDB\Types\Bytes;
use SurrealDB\Types\DateTime;
use SurrealDB\Types\Decimal;
use SurrealDB\Types\Duration;
use SurrealDB\Types\File;
use SurrealDB\Types\GeometryPoint;
use SurrealDB\Types\None;
use SurrealDB\Types\Range;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\RecordIdRange;
use SurrealDB\Types\Set;
use SurrealDB\Types\StringRecordId;
use SurrealDB\Types\Table;
use SurrealDB\Types\Uuid;
use SurrealDB\Types\Value;

final class ValueMapperTest extends TestCase
{
    public function testDecodeDatetime(): void
    {
        $decoded = ValueMapper::decode(['$datetime' => '2024-01-02T03:04:05Z']);

        $this->assertInstanceOf(DateTime::class, $decoded);
        $this->assertSame('2024-01-02T03:04:05Z', $decoded->toIso());
    }

    public function testDecodeRecordId(): void
    {
        $decoded = ValueMapper::decode(['$recordId' => ['tb' => 'person', 'id' => 'tobie']]);

        $this->assertInstanceOf(RecordId::class, $decoded);
        $this->assertSame('person:tobie', $decoded->escape());
    }

    public function testDecodeRecordIdRange(): void
    {
        $decoded = ValueMapper::decode(['$recordId' => [
            'tb' => 'person',
            'id' => ['$range' => [
                'begin' => ['$boundIncluded' => 1],
                'end' => ['$boundExcluded' => 100],
            ]],
        ]]);

        $this->assertInstanceOf(RecordIdRange::class, $decoded);
        $this->assertSame('person:1..100', $decoded->escape());
    }

    public function testDecodeNestedStructures(): void
    {
        $decoded = ValueMapper::decode([
            'name' => 'Tobie',
            'created' => ['$datetime' => '2024-01-02T03:04:05Z'],
            'tags' => [['$uuid' => '09748193-048a-4bfb-b825-8528cf74fdc1']],
        ]);

        $this->assertSame('Tobie', $decoded['name']);
        $this->assertInstanceOf(DateTime::class, $decoded['created']);
        $this->assertInstanceOf(Uuid::class, $decoded['tags'][0]);
    }

    public function testEncodeProducesTaggedForm(): void
    {
        $this->assertSame(
            ['$uuid' => '09748193-048a-4bfb-b825-8528cf74fdc1'],
            ValueMapper::encode(Uuid::fromString('09748193-048a-4bfb-b825-8528cf74fdc1')),
        );
    }

    /**
     * @return iterable<string, array{Value}>
     */
    public static function roundTripProvider(): iterable
    {
        yield 'datetime' => [DateTime::fromString('2024-01-02T03:04:05.123456789Z')];
        yield 'decimal' => [new Decimal('19.99')];
        yield 'duration' => [Duration::fromString('1h30m')];
        yield 'uuid' => [Uuid::fromString('09748193-048a-4bfb-b825-8528cf74fdc1')];
        yield 'table' => [new Table('person')];
        yield 'record-id' => [new RecordId('person', 'tobie')];
        yield 'string-record-id' => [new StringRecordId('person:tobie')];
        yield 'record-id-range' => [new RecordIdRange('person', new BoundIncluded(1), new BoundExcluded(100))];
        yield 'range' => [new Range(new BoundIncluded(1), new BoundExcluded(10))];
        yield 'geometry' => [new GeometryPoint(1.0, 2.0)];
        yield 'file' => [new File('bucket', '/path.txt')];
        yield 'bytes' => [new Bytes('hello world')];
        yield 'none' => [None::instance()];
        yield 'set' => [new Set([1, 2, 3])];
    }

    #[DataProvider('roundTripProvider')]
    public function testRoundTrip(Value $value): void
    {
        $restored = ValueMapper::decode(ValueMapper::encode($value));

        $this->assertInstanceOf($value::class, $restored);
        $this->assertTrue($value->equals($restored), $value::class . ' did not survive a SQON-J round trip');
    }

    public function testRecordIdWithUuidIdRoundTrip(): void
    {
        $value = new RecordId('person', Uuid::v4());
        $restored = ValueMapper::decode(ValueMapper::encode($value));

        $this->assertTrue($value->equals($restored));
    }
}
