<?php

namespace protocol\http;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\RecordId;
use Surreal\Core\Engines\HttpEngine;
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

    /**
     * @throws SurrealException
     * @throws Exception
     */
    public function testInfo(): void
    {
        $db = $this->getDb();

        $jwt = $db->signin([
            "email" => "beau@user.nl",
            "pass" => "123!",
            "namespace" => "test",
            "database" => "test",
            "scope" => "account"
        ]);

        $db->authenticate($jwt);

        $info = $db->info();

        $this->assertIsArray($info);

        $this->assertArrayHasKey("email", $info);
        $this->assertArrayHasKey("id", $info);
        $this->assertArrayHasKey("pass", $info);

        $this->assertInstanceOf(RecordId::class, $info["id"]);

        $db->close();
    }
}