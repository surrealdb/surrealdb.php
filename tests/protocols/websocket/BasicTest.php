<?php

namespace protocol\websocket;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\None;
use Surreal\Cbor\Types\Record\RecordId;
use Surreal\Surreal;

class BasicTest extends TestCase
{
    /**
     * @throws Exception
     */
    private function getDb(): Surreal
    {
        $db = new Surreal();
        $db->connect("ws://127.0.0.1:8000/rpc", [
            "namespace" => "test",
            "database" => "test"
        ]);

        self::assertTrue($db->status() === 200);

        return $db;
    }

    /**
     * @throws Exception
     */
    public function testUse(): void
    {
        $db = $this->getDb();
        $result = $db->use(["namespace" => "test", "database" => "test"]);

        $this->assertInstanceOf(None::class, $result);
        $db->close();
    }

    /**
     * @throws Exception
     */
    public function testLet(): void
    {
        $db = $this->getDb();

        $result = $db->let("x", 1);
        $this->assertNull($result);
    }

    /**
     * @throws Exception
     */
    public function testUnset(): void
    {
        $db = $this->getDb();

        $result = $db->unset("x");
        $this->assertNull($result);

        $db->close();
    }
}