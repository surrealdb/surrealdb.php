<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Surreal;

class ConnectionTest extends TestCase
{
    public function testWrongConnection(): void
    {
        $db = new Surreal();

        try {
            $db->connect("http://localhost:8001");
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }
    }
}