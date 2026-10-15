<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\TestCase;
use SurrealDB\Types\Duration;

final class DurationTest extends TestCase
{
    public function testParsesCompositeDuration(): void
    {
        $duration = Duration::fromString('1h30m');

        $this->assertSame(5400, $duration->seconds);
        $this->assertSame('1h30m', (string) $duration);
    }

    public function testNormalizesOnFormat(): void
    {
        $this->assertSame('1h30m', (string) Duration::fromString('90m'));
    }

    public function testSubSecondUnits(): void
    {
        $duration = Duration::fromString('500ms');

        $this->assertSame(500_000_000, $duration->nanoseconds);
        $this->assertSame('500ms', (string) $duration);
    }

    public function testZeroDuration(): void
    {
        $this->assertSame('0ns', (string) new Duration());
    }

    public function testEscapeIsBareLiteral(): void
    {
        $this->assertSame('1h30m', Duration::fromString('1h30m')->escape());
    }

    public function testJsonSerializeUsesTaggedForm(): void
    {
        $this->assertSame(['$duration' => '1h30m'], Duration::fromString('1h30m')->jsonSerialize());
    }

    public function testFactoryHelpers(): void
    {
        $this->assertSame(5_000_000_000, Duration::seconds(5)->totalNanoseconds());
        $this->assertSame('2h', (string) Duration::hours(2));
        $this->assertSame('1w', (string) Duration::days(7));
    }

    public function testArithmetic(): void
    {
        $sum = Duration::seconds(1)->add(Duration::milliseconds(500));
        $this->assertSame('1s500ms', (string) $sum);

        $scaled = Duration::seconds(2)->mul(3);
        $this->assertSame(6, $scaled->seconds);

        $divided = Duration::seconds(10)->div(4);
        $this->assertSame('2s500ms', (string) $divided);
    }

    public function testCompactTuple(): void
    {
        $this->assertSame([1, 500_000_000], Duration::fromString('1s500ms')->toCompact());
    }

    public function testEquality(): void
    {
        $this->assertTrue(Duration::fromString('1h')->equals(Duration::minutes(60)));
        $this->assertFalse(Duration::fromString('1h')->equals(Duration::fromString('2h')));
    }
}
