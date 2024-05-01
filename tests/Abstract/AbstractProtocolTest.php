<?php

namespace abstract;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Client\SurrealWebsocket;

class AbstractProtocolTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testTimeout()
    {
        $ws = new SurrealWebsocket(
            host: "ws://localhost:8000/rpc",
            target: ["namespace" => "test", "database" => "test"]
        );

        $this->assertEquals(5, $ws->getTimeout());

        $ws->setTimeout(10);
        $this->assertEquals(10, $ws->getTimeout());

        $ws->close();
    }
}