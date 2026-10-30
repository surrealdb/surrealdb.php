<?php

namespace SurrealDB\Tests\Unit\Codec;

use Beau\CborPHP\classes\TaggedValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Codec\Cbor\CborReader;

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
}
