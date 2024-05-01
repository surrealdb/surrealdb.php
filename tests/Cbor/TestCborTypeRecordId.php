<?php

namespace Cbor;

use Beau\CborPHP\exceptions\CborException;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\CBOR;
use Surreal\Cbor\Types\RecordId;

class TestCborTypeRecordId extends TestCase
{
    const RECORD_ID = "record:some-record";
    const RECORD_CBOR_STRING = "c8727265636f72643a736f6d652d7265636f7264";
    const RECORD_CBOR_ARRAY = "C882667265636F72646B736F6D652D7265636F7264";

    /**
     * @throws CborException
     */
    public function testEncodeRecordId(): void
    {
        $recordId = RecordId::fromString(self::RECORD_ID);
        $result = CBOR::encode($recordId);

        $this->assertEquals(self::RECORD_CBOR_STRING, bin2hex($result));
    }

    /**
     * @throws \Exception
     */
    public function testDecodeRecordId(): void
    {
        $result = CBOR::decode(hex2bin(self::RECORD_CBOR_ARRAY));
        $this->assertInstanceOf(RecordId::class, $result);
    }

    public function testMethods(): void
    {
        /** @var RecordId $result */
        $result = CBOR::decode(hex2bin(self::RECORD_CBOR_ARRAY));
        $this->assertInstanceOf(RecordId::class, $result);

        $this->assertEquals("record", $result->getTable());
        $this->assertEquals("some-record", $result->getId());
    }
}