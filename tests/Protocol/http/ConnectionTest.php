<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Surreal;

class ConnectionTest extends TestCase
{
    public function testWrongConnection(): void
    {
        $db = new Surreal("http://localhost:8001");

        try {
            $db->connect();
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }
    }
}