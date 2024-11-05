<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Surreal;

class ExportTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testExport(): void
    {
        $db = new Surreal();
        $db->connect("http://localhost:8000", [
            "namespace" => "test",
            "database" => "test"
        ]);

        $result = $db->export("root", "root");
        $this->assertIsString($result);

        $db->close();
    }
}