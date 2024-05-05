<?php

namespace protocol\http;

use Beau\CborPHP\exceptions\CborException;
use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\None;
use Surreal\Cbor\Types\RecordId;
use Surreal\Cbor\Types\Table;
use Surreal\Exceptions\SurrealException;
use Surreal\Surreal;

class QueryTest extends TestCase
{
    private function getDb(): Surreal
    {
        $db = new Surreal("http://localhost:8000");
        $db->connect();
        $db->use(["namespace" => "test", "database" => "test"]);

        $token = $db->signin([
            "user" => "root",
            "pass" => "root"
        ]);

        $this->assertIsString($token, "Token is not a string");

        return $db;
    }

    /**
     * @throws Exception
     */
    public function testCrudActions(): void
    {
        $db = $this->getDb();

        $id = RecordId::create("person", "julian");
        $db->create($id, ["name" => "Julian", "age" => 24]);

        $id = RecordId::create("person", "beau");
        $response = $db->create($id, ["name" => "Beau", "age" => 18]);

        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $id = RecordId::create("person", "beau");
        $response = $db->update($id, ["age" => 19]);

        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $id = RecordId::create("person", "beau");
        $response = $db->merge($id, ["name" => "Beau", "age" => 25]);

        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);
        $this->assertArrayHasKey("name", $response);
        $this->assertArrayHasKey("age", $response);

        $response = $db->query("SELECT * FROM person WHERE age >= 18");
        $this->assertIsArray($response);

        $id = RecordId::create("person", "beau");
        $response = $db->delete($id);

        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $id = RecordId::create("person", "beau");
        $response = $db->select($id);

        $this->assertInstanceOf(None::class, $response);

        $db->disconnect();
    }

    /**
     * @throws Exception
     */
    public function testPatch(): void
    {
        $id = RecordId::create("person", "beau2");
        $db = $this->getDb();

        $response = $db->create($id, ["name" => "Beau", "age" => 18]);
        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $response = $db->select($id);
        $this->assertIsArray($response);
        $this->assertArrayHasKey("age", $response);

        $response = $db->patch($id, [
            ["op" => "replace", "path" => "/age", "value" => 19]
        ]);

        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $response = $db->select($id);
        $this->assertIsArray($response);
        $this->assertArrayHasKey("age", $response);
        $this->assertEquals(19, $response["age"]);

        $db->delete($id);
        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $response = $db->select($id);
        $this->assertInstanceOf(None::class, $response);

        $db->disconnect();
    }

    /**
     * @throws CborException
     * @throws SurrealException
     */
    public function testInsert(): void
    {
        $db = $this->getDb();
        $tb = Table::create("order");

        $response = $db->insert($tb, [
            ["name" => "Julian", "age" => 24],
            ["name" => "Beau", "age" => 18]
        ]);

        $this->assertIsArray($response);
        $this->assertCount(2, $response);
        $this->assertInstanceOf(RecordId::class, $response[0]["id"]);
        $this->assertInstanceOf(RecordId::class, $response[1]["id"]);

        $db->disconnect();
    }
}