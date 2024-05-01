<?php

namespace protocol\http;

use Beau\CborPHP\exceptions\CborException;
use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\RecordId;
use Surreal\Core\Client\SurrealHTTP;
use Surreal\Exceptions\SurrealException;

class QueryTest extends TestCase
{
    private function getDb(): SurrealHTTP
    {
        $db = new SurrealHTTP(
            host: "http://localhost:8000",
            target: [
                "namespace" => "test",
                "database" => "test"
            ]
        );

        $token = $db->signin([
            "user" => "root",
            "pass" => "root"
        ]);

        self::assertIsString($token, "Token is not a string");
        $db->auth->setToken($token);

        return $db;
    }

    /**
     * @throws Exception
     */
    public function testCrudActions(): void
    {
        $db = $this->getDb();

        $db->create("person:julian", ["name" => "Julian", "age" => 24]);

        $response = $db->create("person:beau", ["name" => "Beau", "age" => 18]);
        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $response = $db->update("person:beau", ["age" => 19]);
        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $response = $db->merge("person:beau", ["name" => "Beau", "age" => 25]);
        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);
        $this->assertArrayHasKey("name", $response);
        $this->assertArrayHasKey("age", $response);

        $response = $db->query("SELECT * FROM person WHERE age >= 18");
        $this->assertIsArray($response);

        $response = $db->delete("person:beau");
        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $response = $db->select("person:beau");
        $this->assertEmpty($response);
    }

    /**
     * @throws Exception
     */
    public function testPatch(): void
    {
        $db = $this->getDb();

        $response = $db->create("person:beau2", ["name" => "Beau", "age" => 18]);
        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $response = $db->select("person:beau2");
        $this->assertIsArray($response);
        $this->assertArrayHasKey("age", $response);

        $response = $db->patch("person:beau2", [
            ["op" => "replace", "path" => "/age", "value" => 19]
        ]);

        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $response = $db->select("person:beau2");
        $this->assertIsArray($response);
        $this->assertArrayHasKey("age", $response);
        $this->assertEquals(19, $response["age"]);

        $db->delete("person:beau2");
        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $response = $db->select("person:beau2");
        $this->assertEmpty($response);
    }

    /**
     * @throws CborException
     * @throws SurrealException
     */
    public function testInsert(): void
    {
        $db = $this->getDb();

        $response = $db->insert("order", [
            ["name" => "Julian", "age" => 24],
            ["name" => "Beau", "age" => 18]
        ]);

        $this->assertIsArray($response);
        $this->assertCount(2, $response);
        $this->assertInstanceOf(RecordId::class, $response[0]["id"]);
        $this->assertInstanceOf(RecordId::class, $response[1]["id"]);
    }
}