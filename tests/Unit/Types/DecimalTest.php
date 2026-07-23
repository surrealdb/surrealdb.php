<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\TestCase;
use SurrealDB\Types\Decimal;

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

    public function testEqualityIsNumeric(): void
    {
        $this->assertTrue(new Decimal('2.50')->equals(new Decimal('2.5')));
        $this->assertFalse(new Decimal('2.50')->equals(new Decimal('2.51')));
        $this->assertSame(0, new Decimal('2.5')->compareTo('2.5'));
        $this->assertSame(-1, new Decimal('1')->compareTo('2'));
    }
}
