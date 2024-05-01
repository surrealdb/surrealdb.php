<?php

namespace protocol\websocket;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Client\SurrealWebsocket;

class ConnectionTest extends TestCase
{
    private static SurrealWebsocket $db;

    /**
     * @throws Exception
     */
    public static function setUpBeforeClass(): void
    {
        self::$db = new SurrealWebsocket(
            host: "ws://localhost:8000/rpc",
            target: ["namespace" => "test", "database" => "test"]
        );

        parent::setUpBeforeClass();
    }

    public function testConnection(): void
    {
        $connected = self::$db->isConnected();
        $this->assertTrue($connected, "The websocket is not connected");
    }

    public function testTimeout(): void
    {
        self::$db->setTimeout(10);
        $this->assertEquals(10, self::$db->getTimeout(), "The timeout is not set correctly");

        self::$db->setTimeout(5);
        $this->assertEquals(5, self::$db->getTimeout(), "The timeout is not set correctly");

        self::$db->setTimeout(0);
        $this->assertEquals(0, self::$db->getTimeout(), "The timeout is not set correctly");
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->close();
        parent::tearDownAfterClass();
    }
}