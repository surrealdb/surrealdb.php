<?php

use Beau\CborPHP\exceptions\CborException;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\CBOR;
use Surreal\Cbor\Types\Record\StringRecordId;

class StringRecordIdTest extends TestCase
{
    const RECORD_ID_STRING = "record:some-record";

    /**
     * @throws CborException
     * @throws Exception
     */
    public function testEncodeStringRecordId()
    {
        $stringRecordId = new StringRecordId(self::RECORD_ID_STRING);
        $result = CBOR::encode($stringRecordId);

        $actual = "c8727265636f72643a736f6d652d7265636f7264";
        $hex = bin2hex($result);

        $this->assertEquals($actual, $hex);

        $this->assertTrue($stringRecordId->equals(StringRecordId::create(self::RECORD_ID_STRING)));
        $this->assertEquals('"' . self::RECORD_ID_STRING . '"', json_encode($stringRecordId));

        $this->assertEquals(self::RECORD_ID_STRING, strval($stringRecordId));
    }

    public function testInstantiateStringRecordId()
    {
        $stringRecordId = new StringRecordId(self::RECORD_ID_STRING);
        $this->assertEquals(self::RECORD_ID_STRING, $stringRecordId->toString());
    }

    public function testEquals()
    {
        $strid1 = new StringRecordId(self::RECORD_ID_STRING);
        $strid2 = new StringRecordId(self::RECORD_ID_STRING);

        $this->assertTrue($strid1->equals($strid2));

        $strid3 = new StringRecordId("record:some-other-record-1");
        $this->assertFalse($strid1->equals($strid3));
    }

    public function testJSONSerialize()
    {
        $strid = new StringRecordId(self::RECORD_ID_STRING);
        $this->assertEquals('"' . self::RECORD_ID_STRING . '"', json_encode($strid));

        // reparsing the JSON should give the same string
        $this->assertEquals(self::RECORD_ID_STRING, json_decode(json_encode($strid), true));
    }

    public function testCreate()
    {
        $strid = StringRecordId::create(self::RECORD_ID_STRING);
        $this->assertEquals(self::RECORD_ID_STRING, $strid->toString());
    }
}