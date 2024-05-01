<?php

namespace Core\Utils;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\RecordId;
use Surreal\Cbor\Types\Table;
use Surreal\Core\Utils\ThingParser;

class ThingParserTest extends TestCase
{
    public function testStringRecord(): void
    {
        $record = "record:some-record";
        $thing = ThingParser::from($record);

        $this->assertTrue($thing->isRecordId());
        $this->assertFalse($thing->isTable());

        $this->assertEquals($record, $thing->toString());
        $this->assertEquals($record, (string)$thing);

        $this->assertEquals("some-record", $thing->getRecordId()->getId());
    }

    public function testClassRecordFromString(): void
    {
        $record = RecordId::fromString("record:some-record");
        $thing = ThingParser::from($record);

        $this->assertTrue($thing->isRecordId());
        $this->assertFalse($thing->isTable());

        $this->assertEquals("record:some-record", $thing->toString());
        $this->assertEquals("record:some-record", (string)$thing);

        $this->assertEquals("some-record", $thing->getRecordId()->getId());
    }

    public function testClassRecordFromArray(): void
    {
        $record = RecordId::fromArray(["record", "some-record"]);
        $thing = ThingParser::from($record);

        $this->assertTrue($thing->isRecordId());
        $this->assertFalse($thing->isTable());

        $this->assertEquals("record:some-record", $thing->toString());
        $this->assertEquals("record:some-record", (string)$thing);

        $this->assertEquals("some-record", $thing->getRecordId()->getId());
    }

    public function testStringTable(): void
    {
        $table = "table";
        $thing = ThingParser::from($table);

        $this->assertFalse($thing->isRecordId());
        $this->assertTrue($thing->isTable());

        $this->assertEquals($table, $thing->toString());
        $this->assertEquals($table, (string)$thing);
    }

    public function testClassTableFromString(): void
    {
        $table = Table::fromString("table");
        $thing = ThingParser::from($table);

        $this->assertFalse($thing->isRecordId());
        $this->assertTrue($thing->isTable());

        $this->assertEquals("table", $thing->toString());
        $this->assertEquals("table", (string)$thing);
    }

    public function testGetTableFromRecordClass(): void
    {
        $record = RecordId::fromString("record:some-record");
        $thing = ThingParser::from($record);

        $this->assertEquals("record", $thing->getTable());
    }

    public function testIsNotRecordId(): void
    {
        $table = "table";
        $thing = ThingParser::from($table);

        $this->assertFalse($thing->isRecordId());

        try {
            $thing->getRecordId();
        } catch (InvalidArgumentException $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
        }
    }
}