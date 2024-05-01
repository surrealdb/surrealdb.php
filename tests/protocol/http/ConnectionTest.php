<?php

namespace protocol\http;

use Beau\CborPHP\exceptions\CborException;
use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Client\SurrealHTTP;

class ConnectionTest extends TestCase
{
    public function testWrongConnection(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8001", // <-- wrong port
            target: ["namespace" => "test", "database" => "test"]
        );

        try {
            $db->query("SELECT * FROM person");
        } catch (Exception $e) {
            $this->assertStringStartsWith("Failed to connect to localhost port 8001", $e->getMessage());
            $this->assertInstanceOf(Exception::class, $e);
        } catch (CborException $e) {
        }
    }
}