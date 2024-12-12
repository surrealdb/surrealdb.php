<?php

namespace v2\query;

use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\Future;
use Surreal\Surreal;

class FutureTest extends TestCase
{
    private function getDb(): Surreal
    {
        $db = new Surreal();
        $db->connect("http://localhost:8000", [
            "namespace" => "test",
            "database" => "test"
        ]);

        $token = $db->signin([
            "user" => "root",
            "pass" => "root"
        ]);

        $this->assertIsString($token, "Token is not a string");

        return $db;
    }

    public function testFutureQuery(): void
    {
        $db = $this->getDb();

        $future = new Future("{ duration::years(time::now() - birthday) >= 18 }");
        $db->let("canDrive", $future);

        $response = $db->queryRaw('
            CREATE future_test
            SET
                birthday = time::now(),
                can_drive = $canDrive
        ');

        $this->assertIsArray($response);

        [$data] = $response;

        $this->assertArrayHasKey("result", $data);
        $this->assertArrayHasKey("time", $data);
        $this->assertArrayHasKey("status", $data);

        $this->assertEquals("OK", $data["status"]);
        $this->assertFalse($data["result"][0]["can_drive"]);

        $db->close();
    }
}