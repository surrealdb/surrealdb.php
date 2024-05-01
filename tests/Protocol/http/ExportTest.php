<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Client\SurrealHTTP;

class ExportTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testExport(): void
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: ["namespace" => "test", "database" => "test"]
        );

        $result = $db->export("root", "root");
        $this->assertIsString($result);

        $db->close();
    }
}