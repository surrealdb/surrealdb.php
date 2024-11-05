<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\Record\RecordId;
use Surreal\Exceptions\SurrealException;
use Surreal\Surreal;

final class BasicTest extends TestCase
{

    private function getDb(): Surreal
    {
        $db = new Surreal();

        $db->connect("http://localhost:8000", [
            "namespace" => "test",
            "database" => "test"
        ]);

        return $db;
    }

    /**
     * @throws Exception
     */
    public function testStatus(): void
    {
        $db = $this->getDb();

        $status = $db->status();

        $this->assertIsInt($status);
        $this->assertEquals(200, $status);

        $db->close();
    }

    /**
     * @throws Exception
     */
    public function testHealth(): void
    {
        $db = $this->getDb();

        $health = $db->health();

        $this->assertIsInt($health);
        $this->assertEquals(200, $health);

        $db->close();
    }

    /**
     * @throws Exception
     */
    public function testVersion(): void
    {
        $db = $this->getDb();

        $version = $db->version();

        $this->assertIsString($version);
        $this->assertStringStartsWith("surrealdb-", $version);

        $db->close();
    }
}