<?php

use Beau\CborPHP\exceptions\CborException;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\CBOR;
use Surreal\Cbor\Types\Record\RecordId;

class RecordIdTest extends TestCase
{
    const RECORD_ID = ["record", "some-record"];
    const RECORD_CBOR_ARRAY = "c882667265636f72646b736f6d652d7265636f7264";
    const RECORD_EMPTY_IDENT = "c882667265636f726460";

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

        $newResult = CBOR::decode(hex2bin(self::RECORD_CBOR_ARRAY));
        $this->assertTrue($result->equals($newResult));
        $this->assertTrue($result->equals("record:⟨some-record⟩"));

        $this->assertEquals('"record:\u27e8some-record\u27e9"', json_encode($result));
        $this->assertEquals("record:⟨some-record⟩", $result->toString());
        $this->assertEquals("record:⟨some-record⟩", strval($result));

        // An array where ID is 0 of type string
        /** @var RecordId $result */
        $result = CBOR::decode(hex2bin("c882667265636f72646130"));
        $this->assertEquals("record:0", $result->toString());

        // An array where ID is 0 of type integer
        $result = CBOR::decode(hex2bin("c882667265636f726400"));
        $this->assertEquals("record:0", $result->toString());

        // 8(["record", { "a": 1, "b": 2 }])
        /** @var RecordId $result */
        $result = CBOR::decode(hex2bin("c882667265636f7264a2616101616202"));
        $this->assertEquals(["a" => 1, "b" => 2], $result->getId());
    }

    /**
     * @throws Exception
     */
    public function testEmptyIdent(): void
    {
        /** @var RecordId $result */
        $result = CBOR::decode(hex2bin(self::RECORD_EMPTY_IDENT));
        $this->assertInstanceOf(RecordId::class, $result);

        $this->assertEquals("record", $result->getTable());
        $this->assertEquals("", $result->getId());

        $newResult = CBOR::decode(hex2bin(self::RECORD_EMPTY_IDENT));
        $this->assertTrue($result->equals($newResult));
        $this->assertTrue($result->equals("record:⟨⟩"));

        $this->assertEquals('"record:\u27e8\u27e9"', json_encode($result));
        $this->assertEquals("record:⟨⟩", $result->toString());
        $this->assertEquals("record:⟨⟩", strval($result));
    }
}