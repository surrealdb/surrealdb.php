<?php

namespace protocol\websocket;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Engines\WsEngine;
use Surreal\Surreal;

class ConnectionTest extends TestCase
{
    private static WsEngine $db;

    private function getDb(): Surreal
    {
        $db = new Surreal();
        $db->connect("ws://localhost:8000/rpc", [
            "namespace" => "test",
            "database" => "test"
        ]);

        $connected = $db->status() === 200;
        $this->assertTrue($connected, "The websocket is not connected");

        return $db;
    }

    public function testTimeout(): void
    {
        $db = $this->getDb();

        $db->setTimeout(10);
        $this->assertEquals(10, $db->getTimeout(), "The timeout is not set correctly");

        $db->setTimeout(5);
        $this->assertEquals(5, $db->getTimeout(), "The timeout is not set correctly");

        $db->setTimeout(0);
        $this->assertEquals(0, $db->getTimeout(), "The timeout is not set correctly");

        $db->disconnect();
    }
}