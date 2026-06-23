<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Types\DateTime;

final class DateTimeTest extends TestCase
{
    public function testParsesIsoWithoutFraction(): void
    {
        $dateTime = DateTime::fromString('2024-01-02T03:04:05Z');

        $this->assertSame('2024-01-02T03:04:05Z', $dateTime->toIso());
        $this->assertSame(0, $dateTime->nanoseconds);
    }

    public function testPreservesNanosecondPrecision(): void
    {
        $dateTime = DateTime::fromString('2024-01-02T03:04:05.123456789Z');

        $this->assertSame(123_456_789, $dateTime->nanoseconds);
        $this->assertSame('2024-01-02T03:04:05.123456789Z', $dateTime->toIso());
    }

    public function testTrimsTrailingZerosInFraction(): void
    {
        $dateTime = DateTime::fromString('2024-01-02T03:04:05.500Z');

        $this->assertSame('2024-01-02T03:04:05.5Z', $dateTime->toIso());
    }

    public function testEscapeUsesDatetimeLiteral(): void
    {
        $dateTime = DateTime::fromString('2024-01-02T03:04:05Z');

        $this->assertSame('d"2024-01-02T03:04:05Z"', $dateTime->escape());
    }

    public function testJsonSerializeUsesTaggedForm(): void
    {
        $dateTime = DateTime::fromString('2024-01-02T03:04:05Z');

        $this->assertSame(['$datetime' => '2024-01-02T03:04:05Z'], $dateTime->jsonSerialize());
    }

    public function testToCompactAndBack(): void
    {
        $dateTime = DateTime::fromString('2024-01-02T03:04:05.250Z');
        [$seconds, $nanoseconds] = $dateTime->toCompact();

        $this->assertTrue(new DateTime($seconds, $nanoseconds)->equals($dateTime));
    }

    public function testFromNativeDateTime(): void
    {
        $native = new \DateTimeImmutable('2024-01-02T03:04:05Z');
        $dateTime = DateTime::fromDateTime($native);

        $this->assertSame($native->getTimestamp(), $dateTime->seconds);
    }

    public function testOffsetIsNormalizedToUtc(): void
    {
        $dateTime = DateTime::fromString('2024-01-02T05:04:05+02:00');

        $this->assertSame('2024-01-02T03:04:05Z', $dateTime->toIso());
    }
}
