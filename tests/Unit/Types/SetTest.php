<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\Codec\CborDeserializer;
use SurrealDB\Codec\CborSerializer;
use SurrealDB\Types\BoundExcluded;
use SurrealDB\Types\BoundIncluded;
use SurrealDB\Types\Bytes;
use SurrealDB\Types\DateTime;
use SurrealDB\Types\Decimal;
use SurrealDB\Types\Duration;
use SurrealDB\Types\File;
use SurrealDB\Types\Future;
use SurrealDB\Types\GeometryPoint;
use SurrealDB\Types\None;
use SurrealDB\Types\Range;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\Set;
use SurrealDB\Types\StringRecordId;
use SurrealDB\Types\Table;
use SurrealDB\Types\Uuid;
use SurrealDB\Types\Value;

final class SetTest extends TestCase
{
    /**
     * Comparing every member with every other took 2.5 s for 20,000 ints and
     * 12 s for 20,000 record ids. In linear time both take milliseconds; the
     * limit leaves room for a slow machine.
     */
    public function testDecodesLargeSetsInLinearTime(): void
    {
        $members = 20_000;
        $ints = "\xd8\x38\x99" . pack('n', $members);

        for ($i = 0; $i < $members; $i++) {
            $ints .= "\x1a" . pack('N', $i);
        }

        $recordIds = (new CborSerializer())->serialize(new Set(array_map(
            static fn (int $i): RecordId => new RecordId('t', $i),
            range(1, $members),
        )));

        foreach (['ints' => $ints, 'record ids' => $recordIds] as $kind => $bytes) {
            $started = microtime(true);
            $set = (new CborDeserializer())->deserialize($bytes);
            $seconds = microtime(true) - $started;

            $this->assertInstanceOf(Set::class, $set);
            $this->assertCount($members, $set->values);
            $this->assertLessThan(1.0, $seconds, "Decoding a set of {$members} {$kind} took {$seconds} s");
        }
    }

    public function testMembersOfDifferentTypesStayDistinct(): void
    {
        $members = [1, 1.0, '1', true, null, false, 0, '', 0.0, [1], [1.0], ['1'], ['a' => 1], [1 => 'a']];

        $this->assertSame($members, new Set([...$members, ...$members])->values);
    }

    /**
     * The members kept are the ones that comparing every pair keeps: a value
     * object's equals(), and === for anything else.
     */
    public function testKeepsWhatComparingEveryPairKeeps(): void
    {
        $object = new \stdClass();
        $uuid = Uuid::fromString('09748193-048a-4bfb-b825-8528cf74fdc1');
        $pool = [
            0, 1, -1, PHP_INT_MAX, 0.0, -0.0, 1.0, 1.5, NAN, INF, '', '0', '1', 'a', "a\0", true, false, null,
            [], [1], [1.0], ['1'], [[1]], ['a' => 1], ['a' => 1.0], [1 => 'a'], ['1' => 'a'], [$object], [NAN],
            $object, new \stdClass(), $uuid, Uuid::fromString((string) $uuid), new Decimal('1.0'), new Decimal('1'),
            new RecordId('t', 1), new RecordId('t', '1'), new RecordId('t', [1.0]), new RecordId('t', [1]),
            new RecordId('t', [$object]), new RecordId('t', [NAN]), new Range(null, null), new Range(null, null),
            new Table('a'), new StringRecordId('a'), None::instance(), Duration::seconds(1), new DateTime(1),
        ];
        $members = [];

        mt_srand(56);

        for ($i = 0; $i < 400; $i++) {
            $members[] = $pool[mt_rand(0, count($pool) - 1)];
        }

        $expected = [];

        foreach ($members as $member) {
            foreach ($expected as $kept) {
                if ($kept instanceof Value ? $kept->equals($member) : $kept === $member) {
                    continue 2;
                }
            }

            $expected[] = $member;
        }

        $actual = new Set($members)->values;

        $this->assertCount(count($expected), $actual);

        foreach ($expected as $index => $member) {
            // serialize() tells NAN, -0.0 and arrays holding NAN apart.
            is_object($member)
                ? $this->assertSame($member, $actual[$index])
                : $this->assertSame(serialize($member), serialize($actual[$index]));
        }
    }

    public function testKeepsTheFirstOfEachMemberInOrder(): void
    {
        $this->assertSame([3, 1, 2], new Set([3, 1, 3, 2, 1, 2])->values);
        $this->assertSame([['b' => 1, 'a' => 2], ['a' => 2, 'b' => 1]], new Set([
            ['b' => 1, 'a' => 2],
            ['a' => 2, 'b' => 1],
            ['b' => 1, 'a' => 2],
        ])->values);
    }

    public function testFloatsAreDuplicatesWhenIdentical(): void
    {
        $this->assertCount(1, new Set([0.0, -0.0])->values);
        $this->assertCount(2, new Set([INF, -INF, INF])->values);
        // NAN is identical to nothing, itself included.
        $this->assertCount(2, new Set([NAN, NAN])->values);
    }

    public function testObjectsInArraysAreDuplicatesOnlyOfThemselves(): void
    {
        $object = new \stdClass();

        $this->assertCount(1, new Set([[$object], [$object]])->values);
        $this->assertCount(2, new Set([[$object], [new \stdClass()]])->values);
        $this->assertCount(2, new Set([$object, new \stdClass(), $object])->values);
    }

    /**
     * @return iterable<string, array{Value, Value, Value}>
     */
    public static function valueObjects(): iterable
    {
        $uuid = '09748193-048a-4bfb-b825-8528cf74fdc1';

        yield 'record id' => [new RecordId('t', 1), new RecordId('t', 1), new RecordId('t', '1')];
        yield 'record id with a float' => [new RecordId('t', [1]), new RecordId('t', [1.0]), new RecordId('t', [true])];
        yield 'record id with an object' => [
            new RecordId('t', ['a' => 1, 'b' => [2]]),
            new RecordId('t', (object) ['b' => [2], 'a' => 1]),
            new RecordId('t', ['a' => 1, 'b' => ['2']]),
        ];
        yield 'record id with a uuid' => [
            new RecordId('t', [Uuid::fromString($uuid)]),
            new RecordId('t', [Uuid::fromString($uuid)]),
            new RecordId('t', [$uuid]),
        ];
        yield 'record id on another table' => [new RecordId('t', 1), new RecordId('t', 1), new RecordId('u', 1)];
        yield 'uuid' => [Uuid::fromString($uuid), Uuid::fromString($uuid), Uuid::v4()];
        yield 'decimal' => [new Decimal('2.5'), new Decimal('2.50'), new Decimal('25')];
        yield 'whole decimal' => [new Decimal('100'), new Decimal('100.000'), new Decimal('1')];
        yield 'duration' => [Duration::fromString('1h'), Duration::minutes(60), Duration::fromString('1h1ns')];
        yield 'datetime' => [new DateTime(1, 5), new DateTime(1, 5), new DateTime(15, 0)];
        yield 'table' => [new Table('a'), new Table('a'), new Table('b')];
        yield 'string record id' => [new StringRecordId('t:1'), new StringRecordId('t:1'), new StringRecordId('t:2')];
        yield 'bytes' => [new Bytes('a'), new Bytes('a'), new Bytes('b')];
        yield 'file' => [new File('a', 'bc'), new File('a', '/bc'), new File('ab', 'c')];
        yield 'future' => [new Future('1'), new Future('1'), new Future('2')];
        yield 'none' => [None::instance(), None::instance(), new Table('NONE')];
        // These have no key, so are compared one by one.
        yield 'range' => [
            new Range(new BoundIncluded(1), new BoundExcluded(2)),
            new Range(new BoundIncluded(1), new BoundExcluded(2)),
            new Range(new BoundIncluded(1), new BoundIncluded(2)),
        ];
        yield 'point' => [new GeometryPoint(1.0, 2.0), new GeometryPoint(1.0, 2.0), new GeometryPoint(2.0, 1.0)];
    }

    #[DataProvider('valueObjects')]
    public function testValueObjectsAreDuplicatesWhenEqual(Value $value, Value $equal, Value $different): void
    {
        $set = new Set([$value, $equal, $different, $equal, $different]);

        $this->assertCount(2, $set->values);
        $this->assertSame($value, $set->values[0]);
        $this->assertSame($different, $set->values[1]);
    }
}
