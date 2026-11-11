<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Exceptions\InvalidValueException;
use SurrealDB\SDK\Types\BoundExcluded;
use SurrealDB\SDK\Types\BoundIncluded;
use SurrealDB\SDK\Types\Range;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\Set;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;
use SurrealDB\SDK\Types\Uuid;
use SurrealDB\SDK\Types\Value;

final class RecordIdTest extends TestCase
{
    public function testSimpleRecordId(): void
    {
        $record = new RecordId('person', 'tobie');

        $this->assertSame('person:tobie', $record->escape());
        $this->assertSame(['$recordId' => ['tb' => 'person', 'id' => 'tobie']], $record->jsonSerialize());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function identifiers(): iterable
    {
        yield 'plain' => ['person', 'person'];
        yield 'underscore' => ['_person_2', '_person_2'];
        yield 'leading digit' => ['2fa', '`2fa`'];
        yield 'empty' => ['', '``'];
        yield 'keyword' => ['select', '`select`'];
        yield 'keyword in capitals' => ['SELECT', '`SELECT`'];
        yield 'NONE value' => ['none', '`none`'];
        yield 'boolean value' => ['true', '`true`'];
        yield 'NaN literal' => ['NaN', '`NaN`'];
        yield 'Infinity literal' => ['Infinity', '`Infinity`'];
        yield 'keyword prefix' => ['selection', 'selection'];
        yield 'backtick and backslash' => ['a`b\\', '`a\\`b\\\\`'];
    }

    #[DataProvider('identifiers')]
    public function testIdentifiersAreQuotedWhenNotPlainNames(string $ident, string $escaped): void
    {
        $this->assertSame($escaped, Table::escapeIdent($ident));
    }

    public function testAReservedWordAsATableOrIdIsQuoted(): void
    {
        $this->assertSame('`none`', new Table('none')->escape());
        $this->assertSame('person:`NaN`', new RecordId('person', 'NaN')->escape());
        $this->assertSame('`true`:`false`', new RecordId('true', 'false')->escape());
    }

    public function testIntegerId(): void
    {
        $this->assertSame('person:123', new RecordId('person', 123)->escape());
    }

    public function testFromNamedConstructor(): void
    {
        $record = RecordId::from('person', 'tobie');

        $this->assertSame('person', $record->table);
        $this->assertSame('tobie', $record->id);
        $this->assertSame('person:tobie', $record->escape());
    }

    public function testUuidId(): void
    {
        $uuid = Uuid::fromString('09748193-048a-4bfb-b825-8528cf74fdc1');
        $record = new RecordId('person', $uuid);

        $this->assertSame('person:u"09748193-048a-4bfb-b825-8528cf74fdc1"', $record->escape());
    }

    public function testEscapesUnsafeIdentifiers(): void
    {
        $this->assertSame('`my table`:`tobie smith`', new RecordId('my table', 'tobie smith')->escape());
    }

    public function testEquality(): void
    {
        $this->assertTrue(new RecordId('person', 'tobie')->equals(new RecordId('person', 'tobie')));
        $this->assertFalse(new RecordId('person', 'tobie')->equals(new RecordId('person', 'jaime')));
    }

    /**
     * Ids the server holds as different records.
     *
     * @return iterable<string, array{string|int|array<mixed>|object, string|int|array<mixed>|object}>
     */
    public static function differentIds(): iterable
    {
        $uuid = '09748193-048a-4bfb-b825-8528cf74fdc1';

        yield 'an int and a numeric string' => [1, '1'];
        yield 'two numeric strings' => ['1e3', '1000'];
        yield 'true and 1' => [[true], [1]];
        yield 'null and false' => [[null], [false]];
        yield 'a string and an int in a map' => [['a' => '1'], ['a' => 1]];
        yield 'an int and a float that is not quite it' => [[9_007_199_254_740_993], [9_007_199_254_740_992.0]];
        yield 'a uuid and its text' => [[Uuid::fromString($uuid)], [$uuid]];
        yield 'lists in another order' => [[1, 2], [2, 1]];
        yield 'a list and a map' => [['a'], ['x' => 'a']];
        yield 'an empty list and an empty object' => [[], new \stdClass()];
    }

    /**
     * @param string|int|array<mixed>|object $a
     * @param string|int|array<mixed>|object $b
     */
    #[DataProvider('differentIds')]
    public function testIdsOfDifferentTypesAreDifferentRecords(string|int|array|object $a, string|int|array|object $b): void
    {
        $this->assertFalse(new RecordId('t', $a)->equals(new RecordId('t', $b)));
        $this->assertFalse(new RecordId('t', $b)->equals(new RecordId('t', $a)));
    }

    /**
     * Ids the server holds as the same record.
     *
     * @return iterable<string, array{string|int|array<mixed>|object, string|int|array<mixed>|object}>
     */
    public static function sameIds(): iterable
    {
        $uuid = '09748193-048a-4bfb-b825-8528cf74fdc1';

        yield 'an int and a float' => [[1], [1.0]];
        yield 'zero and negative zero' => [[0], [-0.0]];
        yield 'object keys in another order' => [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]];
        yield 'a stdClass and an array' => [(object) ['a' => 1, 'b' => [1, 2]], ['b' => [1, 2], 'a' => 1]];
        yield 'nested' => [['x' => [['b' => 1, 'a' => 'z']]], ['x' => [['a' => 'z', 'b' => 1.0]]]];
        yield 'a uuid' => [Uuid::fromString($uuid), Uuid::fromString($uuid)];
        yield 'a uuid in a list' => [[Uuid::fromString($uuid)], [Uuid::fromString($uuid)]];
    }

    /**
     * @param string|int|array<mixed>|object $a
     * @param string|int|array<mixed>|object $b
     */
    #[DataProvider('sameIds')]
    public function testIdsOfTheSameValueAreTheSameRecord(string|int|array|object $a, string|int|array|object $b): void
    {
        $this->assertTrue(new RecordId('t', $a)->equals(new RecordId('t', $b)));
        $this->assertTrue(new RecordId('t', $b)->equals(new RecordId('t', $a)));
    }

    /**
     * @return iterable<string, array{array<mixed>|object, string}>
     */
    public static function idsAsText(): iterable
    {
        yield 'a whole float stays a float' => [[1.0], 't:[ 1.0 ]'];
        yield 'negative zero' => [[-0.0, 0.0], 't:[ -0.0, 0.0 ]'];
        yield 'every digit of a float' => [[1.2345678901234567], 't:[ 1.2345678901234567 ]'];
        yield 'a large whole float' => [[123456789012345678.0, -100.0], 't:[ 123456789012345680.0, -100.0 ]'];
        yield 'plain up to 1e21' => [[1e20, 1e21], 't:[ 100000000000000000000.0, 1e21 ]'];
        yield 'plain down to 1e-6' => [[0.000001, 1e-7, -2.5e-10], 't:[ 0.000001, 1e-7, -2.5e-10 ]'];
        yield 'the extremes' => [[5e-324, 1.7976931348623157E308], 't:[ 5e-324, 1.7976931348623157e308 ]'];
        yield 'infinity overflows' => [[INF, -INF], 't:[ 1e309, -1e309 ]'];
        yield 'NaN' => [[NAN], 't:[ NaN ]'];
        yield 'an object as stdClass' => [(object) ['b' => 1.5, 'a' => ['x']], 't:{ "b": 1.5, "a": [ s"x" ] }'];
        yield 'an empty object' => [new \stdClass(), 't:{  }'];
        yield 'an object with numeric keys' => [(object) ['0' => 'x'], 't:{ "0": s"x" }'];
        yield 'a stdClass inside an array' => [[(object) ['a' => 1]], 't:[ { "a": 1 } ]'];
        yield 'a JsonSerializable as what it serializes to' => [[new class implements \JsonSerializable {
            public function jsonSerialize(): string
            {
                return 'a"; DELETE t; --';
            }
        }], 't:[ s"a\"; DELETE t; --" ]'];
    }

    /**
     * @param array<mixed>|object $id
     */
    #[DataProvider('idsAsText')]
    public function testIdsAreWrittenAsSurrealQlThatReadsBackAsTheSameId(array|object $id, string $text): void
    {
        $this->assertSame($text, new RecordId('t', $id)->escape());
        $this->assertSame($text, (string) new RecordId('t', $id));
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function objectsWithoutASurrealQlForm(): iterable
    {
        yield 'a Stringable' => [new class implements \Stringable {
            public function __toString(): string
            {
                return 'a; DELETE t; --';
            }
        }];
        yield 'a native date' => [new \DateTimeImmutable('2024-01-01')];
        yield 'a closure' => [static fn (): string => 'a; DELETE t; --'];
    }

    /**
     * An object's own text is not SurrealQL: written into a query as it is,
     * `a; DELETE t; --` would run. Only the SDK's value types, `stdClass`
     * and `JsonSerializable` objects have a form to write.
     */
    #[DataProvider('objectsWithoutASurrealQlForm')]
    public function testAnObjectWithoutASurrealQlFormIsRefusedRatherThanWrittenAsItsText(object $object): void
    {
        $writes = [
            'a record id' => static fn (): string => new RecordId('t', $object)->escape(),
            'an array id' => static fn (): string => new RecordId('t', ['x', $object])->escape(),
            'an object id' => static fn (): string => new RecordId('t', (object) ['key' => $object])->escape(),
            'a set' => static fn (): string => new Set([$object])->escape(),
            'a range bound' => static fn (): string => new Range(new BoundIncluded($object), null)->escape(),
            'a literal' => static fn (): string => Value::toSurql($object),
        ];

        foreach ($writes as $what => $write) {
            try {
                $written = $write();
            } catch (InvalidValueException $error) {
                $this->assertStringContainsString(get_debug_type($object), $error->getMessage(), $what);

                continue;
            }

            $this->fail("Expected {$what} to refuse the object, but it wrote: {$written}");
        }
    }

    public function testRecordIdRangeBoundsCompareStrictly(): void
    {
        $one = new RecordIdRange('t', new BoundIncluded(1), null);

        $this->assertFalse($one->equals(new RecordIdRange('t', new BoundIncluded('1'), null)));
        $this->assertTrue($one->equals(new RecordIdRange('t', new BoundIncluded(1.0), null)));
    }

    public function testTable(): void
    {
        $this->assertSame('person', new Table('person')->escape());
        $this->assertSame('`odd table`', new Table('odd table')->escape());
        $this->assertSame(['$table' => 'person'], new Table('person')->jsonSerialize());
    }

    public function testStringRecordId(): void
    {
        $record = new StringRecordId('person:tobie');

        $this->assertSame('r"person:tobie"', $record->escape());
        $this->assertSame('person:tobie', (string) $record);
        $this->assertSame(['$recordIdString' => 'person:tobie'], $record->jsonSerialize());
    }

    public function testStringRecordIdFromRecordId(): void
    {
        $record = new StringRecordId(new RecordId('person', 'tobie'));

        $this->assertSame('person:tobie', $record->id);
    }

    public function testRecordIdRangeExcludedEnd(): void
    {
        $range = new RecordIdRange('person', new BoundIncluded(1), new BoundExcluded(100));

        $this->assertSame('person:1..100', $range->escape());
    }

    public function testRecordIdRangeIncludedEnd(): void
    {
        $range = new RecordIdRange('person', new BoundIncluded(1), new BoundIncluded(100));

        $this->assertSame('person:1..=100', $range->escape());
    }
}
