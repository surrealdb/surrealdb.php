<?php

namespace SurrealDB\Tests\Unit\Codec;

use Beau\CborPHP\classes\TaggedValue;
use Beau\CborPHP\exceptions\CborException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Codec\Cbor\CborReader;
use SurrealDB\SDK\Codec\CborDeserializer;
use SurrealDB\SDK\Exceptions\SerializationException;

final class CborReaderTest extends TestCase
{
    /**
     * @return iterable<string,array{string,float}>
     */
    public static function halfPrecisionFloats(): iterable
    {
        yield 'one half' => ['f93800', 0.5];
        yield 'minus a quarter' => ['f9b400', -0.25];
        yield 'one and a half' => ['f93e00', 1.5];
        yield 'largest' => ['f97bff', 65504.0];
        yield 'smallest normal' => ['f90400', 6.103515625E-5];
        yield 'smallest subnormal' => ['f90001', 5.9604644775390625E-8];
        yield 'largest subnormal' => ['f903ff', 6.097555160522461E-5];
        yield 'zero' => ['f90000', 0.0];
        yield 'infinity' => ['f97c00', INF];
        yield 'negative infinity' => ['f9fc00', -INF];
    }

    #[DataProvider('halfPrecisionFloats')]
    public function testHalfPrecisionFloatsDecode(string $hex, float $expected): void
    {
        self::assertSame($expected, CborReader::decode((string) hex2bin($hex)));
    }

    public function testHalfPrecisionSpecialValues(): void
    {
        self::assertNan(CborReader::decode((string) hex2bin('f97e00')));

        $negativeZero = CborReader::decode((string) hex2bin('f98000'));
        self::assertSame(-0.0, $negativeZero);
        self::assertSame('-0', (string) $negativeZero);
    }

    public function testOtherItemsDecodeAsBefore(): void
    {
        // {"a": [1, -2, "x", h'01', true, null, 1.1], "b": 6("y")}
        $data = hex2bin('a2' . '6161' . '87' . '01' . '21' . '6178' . '4101' . 'f5' . 'f6' . 'fb3ff199999999999a'
            . '6162' . 'c6' . '6179');

        $decoded = CborReader::decode((string) $data);

        self::assertIsArray($decoded);
        self::assertSame([1, -2, 'x', "\x01", true, null, 1.1], $decoded['a']);
        self::assertInstanceOf(TaggedValue::class, $decoded['b']);
        self::assertSame(6, $decoded['b']->tag);
        self::assertSame('y', $decoded['b']->value);
    }

    /**
     * Data a server should never send: each must throw, not warn, return a
     * wrong value, or exhaust memory.
     *
     * @return iterable<string,array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'nothing' => [''];
        yield 'truncated uint16' => ['1901'];
        yield 'truncated uint64' => ['1b000000'];
        yield 'truncated text' => ['646162'];
        yield 'truncated half float' => ['f938'];
        yield 'truncated double' => ['fb3ff1'];
        yield 'array missing items' => ['830102'];
        yield 'map missing a value' => ['a16161'];
        yield 'uint above PHP_INT_MAX' => ['1bffffffffffffffff'];
        yield 'negative int below PHP_INT_MIN' => ['3b8000000000000000'];
        yield 'array of u64-max items' => ['9bffffffffffffffff'];
        yield 'byte string longer than the data' => ['5b7fffffffffffffff00'];
        yield 'array longer than the data' => ['9a0001000001'];
        yield 'map longer than the data' => ['ba00010000616101'];
        yield 'tag number above PHP_INT_MAX' => ['dbffffffffffffffff01'];
        yield 'array as a map key' => ['a18001'];
        yield 'tagged map key' => ['a1c6616101'];
        yield 'float map key' => ['a1f9380001'];
        yield 'indefinite-length array' => ['9f01ff'];
        yield 'reserved additional information' => ['1c'];
        yield 'data after the value' => ['0102'];
    }

    #[DataProvider('malformed')]
    public function testMalformedDataThrows(string $hex): void
    {
        $this->expectException(CborException::class);

        CborReader::decode((string) hex2bin($hex));
    }

    public function testIntegerLimitsDecode(): void
    {
        self::assertSame(PHP_INT_MAX, CborReader::decode((string) hex2bin('1b7fffffffffffffff')));
        self::assertSame(PHP_INT_MIN, CborReader::decode((string) hex2bin('3b7fffffffffffffff')));
    }

    public function testDeepNestingThrowsInsteadOfExhaustingMemory(): void
    {
        $this->expectException(CborException::class);

        CborReader::decode(str_repeat("\x81", 200_000) . "\x01");
    }

    public function testNestingUpToTheLimitDecodes(): void
    {
        $value = CborReader::decode(str_repeat("\x81", CborReader::MAX_DEPTH) . "\x01");

        for ($level = 0; $level < CborReader::MAX_DEPTH; $level++) {
            self::assertIsArray($value);
            $value = $value[0];
        }

        self::assertSame(1, $value);
    }

    public function testTheDeserializerReportsMalformedDataAsASerializationError(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Malformed CBOR');

        (new CborDeserializer())->deserialize((string) hex2bin('646162'));
    }
}
