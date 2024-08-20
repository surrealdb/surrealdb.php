<?php

use Beau\CborPHP\exceptions\CborException;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\CBOR;
use Surreal\Cbor\Types\RecordId;

class RecordIdTest extends TestCase
{
    const RECORD_ID = ["record", "some-record"];
    const RECORD_CBOR_ARRAY = "c882667265636f72646b736f6d652d7265636f7264";

    /**
     * @throws CborException
     */
    public function testEncodeRecordId(): void
    {
        $recordId = RecordId::create(...self::RECORD_ID);
        $result = CBOR::encode($recordId);

        $this->assertEquals(self::RECORD_CBOR_ARRAY, bin2hex($result));
    }

    /**
     * @throws \Exception
     */
    public function testDecodeRecordId(): void
    {
        $result = CBOR::decode(hex2bin(self::RECORD_CBOR_ARRAY));
        $this->assertInstanceOf(RecordId::class, $result);
    }

    /**
     * @throws Exception
     */
    public function testMethods(): void
    {
        /** @var RecordId $result */
        $result = CBOR::decode(hex2bin(self::RECORD_CBOR_ARRAY));
        $this->assertInstanceOf(RecordId::class, $result);

        $this->assertEquals("record", $result->getTable());
        $this->assertEquals("some-record", $result->getId());
    }
}