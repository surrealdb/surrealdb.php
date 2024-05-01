<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Client\SurrealHTTP;

class CloseTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testClose(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $db->close();

        try {
            $db->close();
        }
        catch (Exception $e) {
            $this->assertEquals("The database connection is already closed.", $e->getMessage());
            $this->assertInstanceOf(Exception::class, $e);
        }

        try {
            $db->query("SELECT * FROM person");
        } catch (Exception $e) {
            $this->assertEquals("The curl client is not initialized.", $e->getMessage());
            $this->assertInstanceOf(Exception::class, $e);
        }
    }
}