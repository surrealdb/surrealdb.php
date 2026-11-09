<?php

namespace SurrealDB\Tests\Unit\Codec;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Codec\CborDeserializer;
use SurrealDB\SDK\Codec\CborSerializer;
use SurrealDB\SDK\Codec\Cbor\CborWriter;
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

    public function testBytesRoundTripAsBytes(): void
    {
        $bytes = "hello\0world\xff";

        $this->assertSame('4c68656c6c6f00776f726c64ff', bin2hex($this->serializer->serialize(new Bytes($bytes))));

        // A byte string, not text: writing it back must not send it as text.
        $restored = $this->roundTrip(new Bytes($bytes));
        $this->assertInstanceOf(Bytes::class, $restored);
        $this->assertSame($bytes, $restored->bytes);
        $this->assertSame($bytes, (string) $restored);
    }

    public function testATextStringStaysAString(): void
    {
        $this->assertSame('hello', $this->roundTrip('hello'));
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
    public static function highTagValueProvider(): iterable
    {
        $line = new GeometryLine(new GeometryPoint(0.0, 0.0), new GeometryPoint(1.0, 1.0));
        $polygon = new GeometryPolygon(new GeometryLine(
            new GeometryPoint(0.0, 0.0),
            new GeometryPoint(1.0, 0.0),
            new GeometryPoint(1.0, 1.0),
            new GeometryPoint(0.0, 0.0),
        ));

        yield 'record-id-range' => [new RecordIdRange('person', new BoundIncluded(1), new BoundExcluded(100))];
        yield 'record-id-range open end' => [new RecordIdRange('person', new BoundExcluded('a'), null)];
        yield 'range' => [new Range(new BoundIncluded(1), new BoundExcluded(10))];
        yield 'unbounded range' => [new Range(null, null)];
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

    #[DataProvider('highTagValueProvider')]
    public function testHighTagValuesRoundTrip(Value $value): void
    {
        $restored = $this->roundTrip($value);

        $this->assertInstanceOf($value::class, $restored);
        $this->assertTrue($value->equals($restored), $value::class . ' did not survive a CBOR round trip');
    }

    /**
     * Byte-for-byte fixtures, matching what the JS SDK and the server emit.
     *
     * @return iterable<string, array{Value, string}>
     */
    public static function highTagFixtureProvider(): iterable
    {
        yield 'range 1..10' => [new Range(new BoundIncluded(1), new BoundExcluded(10)), 'd83182d83201d8330a'];
        yield 'open range' => [new Range(new BoundIncluded(1), null), 'd83182d83201f6'];
        yield 'record id range' => [
            new RecordIdRange('person', new BoundIncluded(1), new BoundIncluded(100)),
            'c88266706572736f6ed83182d83201d8321864',
        ];
        yield 'file' => [new File('bucket', '/path.txt'), 'd83782666275636b6574692f706174682e747874'];
        // Tag 37 with the 16 raw bytes: SurrealDB 4.x rejects the text form (tag 9).
        yield 'uuid' => [
            Uuid::fromString('09748193-048a-4bfb-b825-8528cf74fdc1'),
            'd8255009748193048a4bfbb8258528cf74fdc1',
        ];
        yield 'set' => [new Set([1, 2, 3]), 'd83883010203'];
        yield 'point' => [new GeometryPoint(1.0, 2.0), 'd85882fb3ff0000000000000fb4000000000000000'];
        yield 'line' => [
            new GeometryLine(new GeometryPoint(0.0, 0.0), new GeometryPoint(1.0, 1.0)),
            'd85982d85882fb0000000000000000fb0000000000000000d85882fb3ff0000000000000fb3ff0000000000000',
        ];
    }

    #[DataProvider('highTagFixtureProvider')]
    public function testHighTagValuesMatchReferenceHex(Value $value, string $hex): void
    {
        $this->assertSame($hex, bin2hex($this->serializer->serialize($value)));
    }

    public function testDecodesADurationLongerThan292Years(): void
    {
        // Tag 14 [9460800000, 0], as every server sends for `RETURN 300y`.
        $compact = $this->deserializer->deserialize(hex2bin('ce821b0000000233e85a0000'));
        // Tag 13 "600y".
        $text = $this->deserializer->deserialize(hex2bin('cd6436303079'));

        $this->assertInstanceOf(Duration::class, $compact);
        $this->assertSame('300y', (string) $compact);
        $this->assertInstanceOf(Duration::class, $text);
        $this->assertSame([18_921_600_000, 0], $text->toCompact());
    }

    public function testEncodesADurationLongerThan292Years(): void
    {
        $this->assertSame('ce821b0000000233e85a0000', bin2hex($this->serializer->serialize(Duration::years(300))));
        $this->assertTrue(Duration::years(300)->equals($this->roundTrip(Duration::years(300))));
    }

    public function testDecodingADecimalWithAHugeExponentThrows(): void
    {
        // Tag 10 "1e999999999": brick/math exhausted memory building it, a
        // fatal error no caller could catch.
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Invalid decimal in a SurrealDB CBOR tag: "1e999999999"');

        $this->deserializer->deserialize(hex2bin('ca6b3165393939393939393939'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDecimalText(): iterable
    {
        yield 'Arabic-Indic digits' => ["\u{0661}\u{0662}\u{0663}"];
        yield 'fullwidth digits' => ["\u{FF11}"];
        yield 'a three-digit exponent' => ['1e100'];
        yield 'an exponent without digits' => ['1e'];
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'NaN' => ['NaN'];
        yield 'a point without a fraction' => ['1.'];
        yield 'a fraction without an integer' => ['.5'];
        yield 'hexadecimal' => ['0x10'];
        yield 'spaces' => [' 1'];
        yield 'a trailing newline' => ["1\n"];
        yield 'two signs' => ['--1'];
        yield 'more digits than any decimal holds' => ['1.' . str_repeat('0', 64)];
    }

    #[DataProvider('invalidDecimalText')]
    public function testDecodingInvalidDecimalTextThrowsASerializationException(string $text): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Invalid decimal in a SurrealDB CBOR tag');

        $this->deserializer->deserialize("\xca" . CborWriter::encode($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validDecimalText(): iterable
    {
        yield 'smallest' => ['0.0000000000000000000000000001', '0.0000000000000000000000000001'];
        yield 'most negative' => ['-79228162514264337593543950335', '-79228162514264337593543950335'];
        yield 'scale 28 from SurrealDB 2.x' => ['1.0000000000000000000000000000', '1.0000000000000000000000000000'];
        yield 'a plus sign' => ['+1.5', '1.5'];
        yield 'a small exponent' => ['1.5E-7', '0.00000015'];
        yield 'a large exponent' => ['1e3', '1000'];
        yield 'as many digits as allowed' => ['1.' . str_repeat('0', 62) . '1', '1.' . str_repeat('0', 62) . '1'];
    }

    #[DataProvider('validDecimalText')]
    public function testDecodesValidDecimalText(string $text, string $decimal): void
    {
        $decoded = $this->deserializer->deserialize("\xca" . CborWriter::encode($text));

        $this->assertInstanceOf(Decimal::class, $decoded);
        $this->assertSame($decimal, (string) $decoded);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function decimalsSent(): iterable
    {
        yield 'thirty zeros' => ['0.' . str_repeat('0', 30), '0.' . str_repeat('0', 28)];
        yield 'a whole number with thirty zeros' => ['100.' . str_repeat('0', 30), '100.' . str_repeat('0', 28)];
        yield 'two zeros past 28 digits' => ['0.' . str_repeat('1', 28) . '00', '0.' . str_repeat('1', 28)];
        yield 'a zero past a 29th digit' => ['0.' . str_repeat('1', 29) . '0', '0.' . str_repeat('1', 29)];
        yield 'forty digits, kept' => ['0.' . str_repeat('1', 40), '0.' . str_repeat('1', 40)];
        yield 'zeros inside the excess, kept' => ['0.' . str_repeat('1', 28) . '01', '0.' . str_repeat('1', 28) . '01'];
        yield 'trailing zeros within 28 digits, kept' => ['1.50', '1.50'];
        yield 'a whole number' => ['1000', '1000'];
    }

    #[DataProvider('decimalsSent')]
    public function testEncodingDropsOnlyZerosPast28FractionDigits(string $decimal, string $sent): void
    {
        $this->assertSame(
            bin2hex("\xca" . CborWriter::encode($sent)),
            bin2hex($this->serializer->serialize(new Decimal($decimal))),
        );
    }

    public function testEmptyObjectEncodesAsAMap(): void
    {
        $this->assertSame('a0', bin2hex($this->serializer->serialize(new \stdClass())));
        $this->assertSame('80', bin2hex($this->serializer->serialize([])));
    }

    private function roundTrip(mixed $value): mixed
    {
        return $this->deserializer->deserialize($this->serializer->serialize($value));
    }
}
