<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Engines\HttpEngine;
use Surreal\Surreal;

class CloseTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testClose(): void
    {
        $db = new Surreal();

        $db->connect("http://localhost:8000", [
            "namespace" => "test",
            "database" => "test"
        ]);

        $status = $db->status();
        $this->assertEquals(200, $status);

        $db->close();

        try {
            $db->status();
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }
    }
}