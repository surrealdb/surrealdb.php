<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Types\BoundExcluded;
use SurrealDB\SDK\Types\BoundIncluded;
use SurrealDB\SDK\Types\Range;

final class RangeTest extends TestCase
{
    public function testInclusiveRange(): void
    {
        $this->assertSame('1..=10', new Range(new BoundIncluded(1), new BoundIncluded(10))->escape());
    }

    public function testExclusiveBeginInclusiveEnd(): void
    {
        $this->assertSame('1>..=10', new Range(new BoundExcluded(1), new BoundIncluded(10))->escape());
    }

    public function testHalfOpenRanges(): void
    {
        $this->assertSame('1..', new Range(new BoundIncluded(1), null)->escape());
        $this->assertSame('..=10', new Range(null, new BoundIncluded(10))->escape());
        $this->assertSame('..', new Range(null, null)->escape());
    }

    public function testJsonSerialize(): void
    {
        $range = new Range(new BoundIncluded(1), new BoundExcluded(10));

        $this->assertSame(
            ['$range' => [
                'begin' => ['$boundIncluded' => 1],
                'end' => ['$boundExcluded' => 10],
            ]],
            json_decode(json_encode($range), true),
        );
    }

    public function testBoundSerialization(): void
    {
        $this->assertSame(['$boundIncluded' => 5], new BoundIncluded(5)->jsonSerialize());
        $this->assertSame(['$boundExcluded' => 5], new BoundExcluded(5)->jsonSerialize());
    }

    public function testEquality(): void
    {
        $a = new Range(new BoundIncluded(1), new BoundExcluded(10));
        $b = new Range(new BoundIncluded(1), new BoundExcluded(10));
        $c = new Range(new BoundIncluded(1), new BoundIncluded(10));

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
