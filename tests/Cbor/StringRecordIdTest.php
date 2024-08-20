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

        $this->assertEquals($actual, bin2hex($result));
    }
}