<?php

use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\Uuid;

class UuidTest extends TestCase
{
    /**
     * @throws \Random\RandomException
     */
    public function testUuid()
    {
        $uuidString = "01917189-1ae3-7917-abe4-d1a8a550792c";
        $uuid = Uuid::fromString($uuidString);

        $this->assertTrue(Uuid::isUuid($uuid));
        $this->assertTrue(Uuid::isUuid($uuidString));

        $this->assertEquals($uuidString, Uuid::fromString($uuidString)->toString());
        $this->assertEquals('"' . $uuidString . '"', json_encode($uuid));

        $v4 = Uuid::v4();
        $this->assertTrue(Uuid::isUuid($v4));

        $v7 = Uuid::v7();
        $this->assertTrue(Uuid::isUuid($v7));

        // Generate bytes that represents a UUID.
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        $uuid = Uuid::fromBytes($bytes);
        $this->assertTrue(Uuid::isUuid($uuid));

        $this->assertEquals($uuid->getBytes(), $bytes);
    }
}