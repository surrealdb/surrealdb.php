<?php

namespace SurrealDB\Tests\Unit\Codec;

use Beau\CborPHP\classes\TaggedValue;
use Beau\CborPHP\utils\CborByteString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Codec\Cbor\CborMap;
use SurrealDB\SDK\Codec\Cbor\CborWriter;
use SurrealDB\SDK\Exceptions\SerializationException;

/**
 * Encodings checked against RFC 8949 Appendix A.
 */
final class CborWriterTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function vectors(): iterable
    {
        yield '0' => [0, '00'];
        yield '23' => [23, '17'];
        yield '24' => [24, '1818'];
        yield '1000' => [1000, '1903e8'];
        yield '1000000' => [1000000, '1a000f4240'];
        yield '1000000000000' => [1000000000000, '1b000000e8d4a51000'];
        yield 'max int' => [PHP_INT_MAX, '1b7fffffffffffffff'];
        yield '-1' => [-1, '20'];
        yield '-1000' => [-1000, '3903e7'];
        yield 'min int' => [PHP_INT_MIN, '3b7fffffffffffffff'];
        yield '1.1' => [1.1, 'fb3ff199999999999a'];
        yield 'NaN' => [NAN, 'f97e00'];
        yield 'false' => [false, 'f4'];
        yield 'true' => [true, 'f5'];
        yield 'null' => [null, 'f6'];
        yield 'empty text' => ['', '60'];
        yield 'text' => ['IETF', '6449455446'];
        yield 'bytes' => [new CborByteString("\x01\x02\x03\x04"), '4401020304'];
        yield 'array' => [[1, [2, 3], [4, 5]], '8301820203820405'];
        yield 'map' => [['a' => 1, 'b' => [2, 3]], 'a26161016162820203'];
        yield 'int keys' => [[1 => 2, 3 => 4], 'a201020304'];
        yield 'empty map' => [new CborMap(), 'a0'];
        yield 'tag 1' => [new TaggedValue(1, 1363896240), 'c11a514b67b0'];
        yield 'tag 24' => [new TaggedValue(24, 'a'), 'd8186161'];
        yield 'tag 1000' => [new TaggedValue(1000, 1), 'd903e801'];
        yield 'tag 100000' => [new TaggedValue(100000, null), 'da000186a0f6'];
    }

    #[DataProvider('vectors')]
    public function testEncodesRfcVectors(mixed $value, string $hex): void
    {
        $this->assertSame($hex, bin2hex(CborWriter::encode($value)));
    }

    public function testLongStringUsesExtendedLength(): void
    {
        $encoded = CborWriter::encode(str_repeat('x', 300));

        $this->assertSame('79012c', bin2hex(substr($encoded, 0, 3)));
        $this->assertSame(303, strlen($encoded));
    }

    public function testRejectsUnsupportedValues(): void
    {
        $this->expectException(SerializationException::class);

        CborWriter::encode(new \ArrayObject());
    }
}
