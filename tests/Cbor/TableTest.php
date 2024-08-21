<?php

use Surreal\Cbor\Types\Table;

class TableTest extends \PHPUnit\Framework\TestCase
{
    public function testTable()
    {
        $tableName = "users";
        $table = Table::create($tableName);

        $this->assertEquals($tableName, $table->getTable());
        $this->assertEquals($tableName, $table->table);
        $this->assertEquals($tableName, $table->toString());

        $this->assertTrue($table->equals("users"));

        $newTable = Table::create($tableName);
        $this->assertTrue($newTable->equals($table));

        $faultyTable = Table::create("logs");
        $this->assertFalse($faultyTable->equals($table));

        $this->assertEquals('"logs"', json_encode($faultyTable));
        $this->assertEquals("logs", strval($faultyTable));
    }
}