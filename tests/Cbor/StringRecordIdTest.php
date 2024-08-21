<?php

use Beau\CborPHP\exceptions\CborException;
use Beau\CborPHP\utils\CborByteString;
use Surreal\Cbor\CBOR;
use Surreal\Cbor\Types\RecordId;
use Surreal\Cbor\Types\StringRecordId;

class StringRecordIdTest extends \PHPUnit\Framework\TestCase
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
}