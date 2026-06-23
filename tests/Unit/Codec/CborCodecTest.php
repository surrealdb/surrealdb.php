<?php

namespace SurrealDB\Tests\Unit\Codec;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Codec\CborDeserializer;
use SurrealDB\SDK\Codec\CborSerializer;
use SurrealDB\SDK\Exceptions\SerializationException;
use SurrealDB\SDK\Types\BoundExcluded;
use SurrealDB\SDK\Types\BoundIncluded;
use SurrealDB\SDK\Types\Bytes;
use SurrealDB\SDK\Types\DateTime;
use SurrealDB\SDK\Types\Decimal;
use SurrealDB\SDK\Types\Duration;
use SurrealDB\SDK\Types\File;
use SurrealDB\SDK\Types\Future;
use SurrealDB\SDK\Types\GeometryCollection;
use SurrealDB\SDK\Types\GeometryLine;
use SurrealDB\SDK\Types\GeometryMultiLine;
use SurrealDB\SDK\Types\GeometryMultiPoint;
use SurrealDB\SDK\Types\GeometryMultiPolygon;
use SurrealDB\SDK\Types\GeometryPoint;
use SurrealDB\SDK\Types\GeometryPolygon;
use SurrealDB\SDK\Types\None;
use SurrealDB\SDK\Types\Range;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\Set;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;
use SurrealDB\SDK\Types\Uuid;
use SurrealDB\SDK\Types\Value;

final class CborCodecTest extends TestCase
{
    private CborSerializer $serializer;
    private CborDeserializer $deserializer;

    protected function setUp(): void
    {
        $this->serializer = new CborSerializer();
        $this->deserializer = new CborDeserializer();
    }

    public function testPrimitiveRoundTrip(): void
    {
        $value = [
            'name' => 'Tobie',
            'age' => 42,
            'active' => true,
            'score' => 4.5,
            'items' => [1, 2, null],
        ];

        $this->assertSame($value, $this->roundTrip($value));
    }

    public function testRecordIdMatchesReferenceHexFixture(): void
    {
        $encoded = $this->serializer->serialize(new RecordId('record', 'some-record'));

        $this->assertSame('c882667265636f72646b736f6d652d7265636f7264', bin2hex($encoded));
        $this->assertTrue((new RecordId('record', 'some-record'))->equals($this->deserializer->deserialize($encoded)));
    }

    public function testBytesUseCborByteStringButDecodeAsPlainStringThroughPackage(): void
    {
        $bytes = "hello\0world";

        $this->assertSame('4b68656c6c6f00776f726c64', bin2hex($this->serializer->serialize(new Bytes($bytes))));
        $this->assertSame($bytes, $this->roundTrip(new Bytes($bytes)));
    }

    public function testValidHighTagServerPayloadsDecodeThroughCborPackage(): void
    {
        $this->assertInstanceOf(
            Uuid::class,
            $this->deserializer->deserialize(hex2bin('d8255009748193048a4bfbb8258528cf74fdc1')),
        );
        $this->assertInstanceOf(
            Range::class,
            $this->deserializer->deserialize(hex2bin('d83182d83201f6')),
        );
        $this->assertInstanceOf(
            File::class,
            $this->deserializer->deserialize(hex2bin('d83782666275636b6574692f706174682e747874')),
        );
        $this->assertInstanceOf(
            Set::class,
            $this->deserializer->deserialize(hex2bin('d83883010203')),
        );
        $this->assertInstanceOf(
            GeometryPoint::class,
            $this->deserializer->deserialize(hex2bin('d85882fb3ff0000000000000fb4000000000000000')),
        );
    }

    /**
     * @return iterable<string, array{Value}>
     */
    public static function valueProvider(): iterable
    {
        yield 'none' => [None::instance()];
        yield 'table' => [new Table('person')];
        yield 'record-id' => [new RecordId('person', 'tobie')];
        yield 'string-record-id' => [new StringRecordId('person:tobie')];
        yield 'uuid' => [Uuid::fromString('09748193-048a-4bfb-b825-8528cf74fdc1')];
        yield 'decimal' => [new Decimal('1234.5678')];
        yield 'datetime' => [DateTime::fromString('2024-01-02T03:04:05.123456789Z')];
        yield 'duration' => [Duration::fromString('1h30m15s')];
        yield 'future' => [new Future('{ time::now() }')];
    }

    #[DataProvider('valueProvider')]
    public function testValueRoundTrip(Value $value): void
    {
        $restored = $this->roundTrip($value);

        $this->assertInstanceOf($value::class, $restored);
        $this->assertTrue($value->equals($restored), $value::class . ' did not survive a CBOR round trip');
    }

    public function testNestedRecordIdUuidRoundTrip(): void
    {
        $value = new RecordId('person', Uuid::fromString('09748193-048a-4bfb-b825-8528cf74fdc1'));
        $restored = $this->roundTrip($value);

        $this->assertInstanceOf(RecordId::class, $restored);
        $this->assertTrue($value->equals($restored));
    }

    /**
     * @return iterable<string, array{Value}>
     */
    public static function unsupportedHighTagValueProvider(): iterable
    {
        $line = new GeometryLine(new GeometryPoint(0.0, 0.0), new GeometryPoint(1.0, 1.0));
        $polygon = new GeometryPolygon(new GeometryLine(
            new GeometryPoint(0.0, 0.0),
            new GeometryPoint(1.0, 0.0),
            new GeometryPoint(1.0, 1.0),
            new GeometryPoint(0.0, 0.0),
        ));

        yield 'record-id-range' => [new RecordIdRange('person', new BoundIncluded(1), new BoundExcluded(100))];
        yield 'range' => [new Range(new BoundIncluded(1), new BoundExcluded(10))];
        yield 'file' => [new File('bucket', '/path.txt')];
        yield 'set' => [new Set([1, 'two', Uuid::fromString('09748193-048a-4bfb-b825-8528cf74fdc1')])];
        yield 'point' => [new GeometryPoint(1.0, 2.0)];
        yield 'line' => [$line];
        yield 'polygon' => [$polygon];
        yield 'multi-point' => [new GeometryMultiPoint(new GeometryPoint(1.0, 2.0), new GeometryPoint(3.0, 4.0))];
        yield 'multi-line' => [new GeometryMultiLine($line)];
        yield 'multi-polygon' => [new GeometryMultiPolygon($polygon)];
        yield 'geometry-collection' => [new GeometryCollection(new GeometryPoint(1.0, 2.0), $line)];
    }

    #[DataProvider('unsupportedHighTagValueProvider')]
    public function testHighTagEncodingFailsBeforeProducingMalformedPackageCbor(Value $value): void
    {
        $this->expectException(SerializationException::class);

        $this->serializer->serialize($value);
    }

    private function roundTrip(mixed $value): mixed
    {
        return $this->deserializer->deserialize($this->serializer->serialize($value));
    }
}
