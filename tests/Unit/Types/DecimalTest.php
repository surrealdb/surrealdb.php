<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Types\Decimal;

final class DecimalTest extends TestCase
{
    public function testStringIsPreserved(): void
    {
        $this->assertSame('19.99', (string) new Decimal('19.99'));
    }

    public function testEscapeUsesDecSuffix(): void
    {
        $this->assertSame('19.99dec', new Decimal('19.99')->escape());
    }

    public function testJsonSerializeUsesTaggedForm(): void
    {
        $this->assertSame(['$decimal' => '19.99'], new Decimal('19.99')->jsonSerialize());
    }

    public function testArbitraryPrecisionAddition(): void
    {
        $sum = new Decimal('0.1')->plus(new Decimal('0.2'));

        $this->assertSame('0.3', (string) $sum);
    }

    public function testMultiplicationAndDivision(): void
    {
        $product = new Decimal('2.5')->multipliedBy('4');
        $quotient = new Decimal('10')->dividedBy('4', 2);

        $this->assertTrue($product->equals(new Decimal('10.0')));
        $this->assertTrue($quotient->equals(new Decimal('2.5')));
    }

    public function testFloatInputAvoidsBinaryNoise(): void
    {
        $this->assertSame('19.99', (string) new Decimal(19.99));
    }

    /**
     * A cast to string keeps 14 significant digits; a float holds up to 17.
     *
     * @return iterable<string, array{float, string}>
     */
    public static function floats(): iterable
    {
        yield 'seventeen digits' => [1.2345678901234567, '1.2345678901234567'];
        yield 'a large whole number' => [123456789012345678.0, '123456789012345680'];
        yield 'binary noise kept as the float holds it' => [0.1 + 0.2, '0.30000000000000004'];
        yield 'a small exponent' => [1.0E-7, '0.0000001'];
        yield 'a large exponent' => [1.0E+25, '10000000000000000000000000'];
        yield 'a whole float' => [100.0, '100'];
        yield 'negative' => [-2.5, '-2.5'];
        yield 'zero' => [0.0, '0'];
    }

    #[DataProvider('floats')]
    public function testFloatInputKeepsTheShortestTextThatReadsBackAsTheFloat(float $float, string $text): void
    {
        $this->assertSame($text, (string) new Decimal($float));
    }

    public function testFloatInputDoesNotDependOnThePrecisionSettings(): void
    {
        $precision = ini_set('precision', '5');
        $serializePrecision = ini_set('serialize_precision', '5');

        try {
            $this->assertSame('1.2345678901234567', (string) new Decimal(1.2345678901234567));
        } finally {
            ini_set('precision', (string) $precision);
            ini_set('serialize_precision', (string) $serializePrecision);
        }
    }

    public function testEqualityIsNumeric(): void
    {
        $this->assertTrue(new Decimal('2.50')->equals(new Decimal('2.5')));
        $this->assertFalse(new Decimal('2.50')->equals(new Decimal('2.51')));
        $this->assertSame(0, new Decimal('2.5')->compareTo('2.5'));
        $this->assertSame(-1, new Decimal('1')->compareTo('2'));
    }
}
