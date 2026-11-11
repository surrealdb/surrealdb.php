<?php

namespace SurrealDB\Tests\Unit\Codec;

use Beau\CborPHP\CborDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\Codec\CborDeserializer;
use SurrealDB\Exceptions\SerializationException;
use SurrealDB\Types\Bytes;
use SurrealDB\Types\Table;

/**
 * What the SDK reads from the server, through welpie21/cbor.php.
 */
final class CborDecodingTest extends TestCase
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
        // SurrealDB sends a float as 16 bits when that loses nothing: RETURN 0.5.
        self::assertSame($expected, $this->decode($hex));
    }

    public function testHalfPrecisionSpecialValues(): void
    {
        self::assertNan($this->decode('f97e00'));

        $negativeZero = $this->decode('f98000');
        self::assertSame(-0.0, $negativeZero);
        self::assertSame('-0', (string) $negativeZero);
    }

    public function testOtherItemsDecode(): void
    {
        // {"a": [1, -2, "x", h'01', true, null, 1.1], "b": 7("y")}
        $decoded = $this->decode('a2' . '6161' . '87' . '01' . '21' . '6178' . '4101' . 'f5' . 'f6' . 'fb3ff199999999999a'
            . '6162' . 'c7' . '6179');

        self::assertIsArray($decoded);
        // A byte string comes back as Bytes, apart from text.
        self::assertEquals([1, -2, 'x', new Bytes("\x01"), true, null, 1.1], $decoded['a']);
        self::assertEquals(new Table('y'), $decoded['b']);
    }

    /**
     * Data a server should never send: each must throw, not warn, return a
     * wrong value, or exhaust memory.
     *
     * @return iterable<string,array{string}>
     */
    public static function malformed(): iterable
    {
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
        yield 'bool map key' => ['a1f501'];
        yield 'byte string map key' => ['a1416b01'];
        yield 'duplicate map key' => ['a2616101616102'];
        yield 'indefinite-length array' => ['9f01ff'];
        yield 'reserved additional information' => ['1c'];
        yield 'data after the value' => ['0102'];
    }

    #[DataProvider('malformed')]
    public function testMalformedDataThrows(string $hex): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Malformed CBOR');

        $this->decode($hex);
    }

    public function testNothingDecodesToNull(): void
    {
        self::assertNull((new CborDeserializer())->deserialize(''));
    }

    public function testIntegerLimitsDecode(): void
    {
        self::assertSame(PHP_INT_MAX, $this->decode('1b7fffffffffffffff'));
        self::assertSame(PHP_INT_MIN, $this->decode('3b7fffffffffffffff'));
    }

    public function testDeepNestingThrowsInsteadOfExhaustingMemory(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Nesting deeper than 512 levels');

        (new CborDeserializer())->deserialize(str_repeat("\x81", 200_000) . "\x01");
    }

    public function testNestingUpToTheLimitDecodes(): void
    {
        $value = (new CborDeserializer())->deserialize(str_repeat("\x81", CborDecoder::DEFAULT_MAX_DEPTH) . "\x01");

        for ($level = 0; $level < CborDecoder::DEFAULT_MAX_DEPTH; $level++) {
            self::assertIsArray($value);
            $value = $value[0];
        }

        self::assertSame(1, $value);
    }

    private function decode(string $hex): mixed
    {
        return (new CborDeserializer())->deserialize((string) hex2bin($hex));
    }
}
