<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Types\BoundExcluded;
use SurrealDB\SDK\Types\BoundIncluded;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;
use SurrealDB\SDK\Types\Uuid;

final class RecordIdTest extends TestCase
{
    public function testSimpleRecordId(): void
    {
        $record = new RecordId('person', 'tobie');

        $this->assertSame('person:tobie', $record->escape());
        $this->assertSame(['$recordId' => ['tb' => 'person', 'id' => 'tobie']], $record->jsonSerialize());
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
