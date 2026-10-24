<?php

namespace SurrealDB\Tests\Unit\Codec;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Codec\CborDeserializer;
use SurrealDB\SDK\Codec\CborSerializer;
use SurrealDB\SDK\Types\DateTime;
use SurrealDB\SDK\Types\Duration;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Uuid;

/**
 * SurrealDB 4.0 refuses the text-based CBOR tags in requests: 0 (datetime),
 * 9 (uuid) and 13 (duration). The SDK sends tag 12 and 14 with
 * [seconds, nanoseconds] and tag 37 with the 16 raw bytes instead, which every
 * server since 1.5.0 reads, and still decodes the text tags a server may send.
 */
final class CborTextTagTest extends TestCase
{
    private const string UUID = '09748193-048a-4bfb-b825-8528cf74fdc1';
    private const string UUID_HEX = '09748193048a4bfbb8258528cf74fdc1';

    private CborSerializer $serializer;
    private CborDeserializer $deserializer;

    protected function setUp(): void
    {
        $this->serializer = new CborSerializer();
        $this->deserializer = new CborDeserializer();
    }

    public function testUuidIsSentAsTag37WithSixteenRawBytes(): void
    {
        $encoded = $this->serializer->serialize(Uuid::fromString(self::UUID));

        // d8 25 = tag 37, 50 = byte string of 16, then the bytes.
        $this->assertSame('d825' . '50' . self::UUID_HEX, bin2hex($encoded));
    }

    public function testDateTimeIsSentAsTag12WithSecondsAndNanoseconds(): void
    {
        $encoded = $this->serializer->serialize(DateTime::fromString('2024-01-02T03:04:05.123456789Z'));

        // cc = tag 12, 82 = array of 2, 1a = 32-bit unsigned: 1704164645, 123456789.
        $this->assertSame('cc' . '82' . '1a65937d25' . '1a075bcd15', bin2hex($encoded));
    }

    public function testDurationIsSentAsTag14WithSecondsAndNanoseconds(): void
    {
        $encoded = $this->serializer->serialize(Duration::fromString('1h30m15s'));

        // ce = tag 14, 82 = array of 2, 19 = 16-bit unsigned: 5415, then 0.
        $this->assertSame('ce' . '82' . '191527' . '00', bin2hex($encoded));
    }

    public function testAUuidRecordIdKeepsTag37InsideTheRecordId(): void
    {
        $encoded = $this->serializer->serialize(new RecordId('person', Uuid::fromString(self::UUID)));

        // c8 = tag 8, 82 = array of 2, "person", then the tag 37 uuid.
        $this->assertSame('c8' . '82' . '66' . bin2hex('person') . 'd825' . '50' . self::UUID_HEX, bin2hex($encoded));
    }

    public function testNestedValuesNeverUseTheTextTags(): void
    {
        $encoded = $this->serializer->serialize([
            'id' => Uuid::fromString(self::UUID),
            'at' => DateTime::fromString('2024-01-02T03:04:05Z'),
            'for' => Duration::fromString('90s'),
            'ids' => [Uuid::v4(), Uuid::v7()],
            'owner' => new RecordId('person', Uuid::v4()),
        ]);

        $tags = $this->tagsIn($encoded);

        $this->assertNotContains(0, $tags);
        $this->assertNotContains(9, $tags);
        $this->assertNotContains(13, $tags);
        $this->assertSame(
            [37, 12, 14, 37, 37, 8, 37],
            $tags,
        );
    }

    public function testUuidRoundTripsThroughTag37(): void
    {
        $uuid = Uuid::fromString(self::UUID);
        $restored = $this->deserializer->deserialize($this->serializer->serialize($uuid));

        $this->assertInstanceOf(Uuid::class, $restored);
        $this->assertTrue($uuid->equals($restored));
        $this->assertSame(self::UUID, (string) $restored);
    }

    public function testDateTimeAndDurationRoundTripThroughTags12And14(): void
    {
        $at = DateTime::fromString('2024-01-02T03:04:05.123456789Z');
        $for = Duration::fromString('1h30m15s250ms');

        $restoredAt = $this->deserializer->deserialize($this->serializer->serialize($at));
        $restoredFor = $this->deserializer->deserialize($this->serializer->serialize($for));

        $this->assertInstanceOf(DateTime::class, $restoredAt);
        $this->assertTrue($at->equals($restoredAt));
        $this->assertInstanceOf(Duration::class, $restoredFor);
        $this->assertTrue($for->equals($restoredFor));
    }

    public function testDecodingStillAcceptsTheTextTags(): void
    {
        $datetime = $this->deserializer->deserialize(hex2bin('c0') . $this->text('2024-01-02T03:04:05.123456789Z'));
        $uuid = $this->deserializer->deserialize(hex2bin('c9') . $this->text(self::UUID));
        $duration = $this->deserializer->deserialize(hex2bin('cd') . $this->text('1h30m15s'));

        $this->assertInstanceOf(DateTime::class, $datetime);
        $this->assertTrue(DateTime::fromString('2024-01-02T03:04:05.123456789Z')->equals($datetime));
        $this->assertInstanceOf(Uuid::class, $uuid);
        $this->assertSame(self::UUID, (string) $uuid);
        $this->assertInstanceOf(Duration::class, $duration);
        $this->assertTrue(Duration::fromString('1h30m15s')->equals($duration));
    }

    public function testDecodingStillAcceptsTheBinaryUuid(): void
    {
        $uuid = $this->deserializer->deserialize(hex2bin('d82550' . self::UUID_HEX));

        $this->assertInstanceOf(Uuid::class, $uuid);
        $this->assertSame(self::UUID, (string) $uuid);
    }

    private function text(string $value): string
    {
        $length = strlen($value);
        $this->assertLessThan(256, $length);

        return ($length < 24 ? chr(0x60 | $length) : chr(0x78) . chr($length)) . $value;
    }

    /**
     * Every CBOR tag number in the order it is written, found by walking the
     * item structure rather than scanning bytes.
     *
     * @return list<int>
     */
    private function tagsIn(string $cbor): array
    {
        $tags = [];
        $offset = 0;
        $this->walk($cbor, $offset, $tags);
        $this->assertSame(strlen($cbor), $offset, 'The walker must consume the whole payload.');

        return $tags;
    }

    /**
     * @param list<int> $tags
     */
    private function walk(string $cbor, int &$offset, array &$tags): void
    {
        $initial = ord($cbor[$offset++]);
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === 7) {
            // Simple values and floats: a float carries 2, 4 or 8 more bytes.
            $offset += match ($info) {
                25 => 2,
                26 => 4,
                27 => 8,
                default => 0,
            };

            return;
        }

        $argument = match (true) {
            $info < 24 => $info,
            $info === 24 => ord($cbor[$offset++]),
            $info === 25 => $this->unpack('n', $cbor, $offset, 2),
            $info === 26 => $this->unpack('N', $cbor, $offset, 4),
            default => $this->unpack('J', $cbor, $offset, 8),
        };

        switch ($major) {
            case 2:
            case 3:
                $offset += $argument;

                return;
            case 4:
                for ($i = 0; $i < $argument; $i++) {
                    $this->walk($cbor, $offset, $tags);
                }

                return;
            case 5:
                for ($i = 0; $i < $argument * 2; $i++) {
                    $this->walk($cbor, $offset, $tags);
                }

                return;
            case 6:
                $tags[] = $argument;
                $this->walk($cbor, $offset, $tags);

                return;
        }
    }

    private function unpack(string $format, string $cbor, int &$offset, int $length): int
    {
        $values = unpack($format, substr($cbor, $offset, $length));
        $offset += $length;

        return (int) ($values[1] ?? 0);
    }
}
