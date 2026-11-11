<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\TestCase;
use SurrealDB\Types\Uuid;

final class UuidTest extends TestCase
{
    private const string SAMPLE = '09748193-048a-4bfb-b825-8528cf74fdc1';

    public function testFromStringRoundTrips(): void
    {
        $uuid = Uuid::fromString(self::SAMPLE);

        $this->assertSame(self::SAMPLE, (string) $uuid);
    }

    public function testEscapeUsesUuidLiteral(): void
    {
        $uuid = Uuid::fromString(self::SAMPLE);

        $this->assertSame('u"' . self::SAMPLE . '"', $uuid->escape());
    }

    public function testJsonSerializeUsesTaggedForm(): void
    {
        $uuid = Uuid::fromString(self::SAMPLE);

        $this->assertSame(['$uuid' => self::SAMPLE], $uuid->jsonSerialize());
    }

    public function testV4GeneratesValidRandomUuid(): void
    {
        $uuid = Uuid::v4();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $uuid,
        );
    }

    public function testV7GeneratesValidTimeOrderedUuid(): void
    {
        $uuid = Uuid::v7();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $uuid,
        );
    }

    public function testBinaryRoundTrip(): void
    {
        $uuid = Uuid::fromString(self::SAMPLE);
        $restored = Uuid::fromBytes($uuid->toBytes());

        $this->assertTrue($uuid->equals($restored));
        $this->assertSame(16, strlen($uuid->toBytes()));
    }

    public function testEquality(): void
    {
        $this->assertTrue(Uuid::fromString(self::SAMPLE)->equals(Uuid::fromString(self::SAMPLE)));
        $this->assertFalse(Uuid::fromString(self::SAMPLE)->equals(Uuid::v4()));
        $this->assertFalse(Uuid::fromString(self::SAMPLE)->equals('not a uuid'));
    }
}
