<?php

namespace protocol\http;

use Beau\CborPHP\exceptions\CborException;
use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\Future;
use Surreal\Cbor\Types\None;
use Surreal\Cbor\Types\Record\RecordId;
use Surreal\Cbor\Types\Table;
use Surreal\Exceptions\SurrealException;
use Surreal\Surreal;

class QueryTest extends TestCase
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

        [$responseA, $responseB] = $db->query("SELECT * FROM person WHERE age >= 18; SELECT * FROM person WHERE age = 24");
        $this->assertIsArray($response);
        $this->assertCount(2, $responseA);

        foreach ($responseA as $record) {
            $this->assertArrayHasKey("name", $record);
            $this->assertArrayHasKey("age", $record);
            $this->assertTrue($record["age"] >= 18);
        }

        foreach ($responseB as $record) {
            $this->assertArrayHasKey("name", $record);
            $this->assertArrayHasKey("age", $record);
            $this->assertEquals(24, $record["age"]);
        }

        $response = $db->queryRaw("SELECT * FROM person WHERE age >= 18");
        $this->assertIsArray($response);
        $this->assertCount(1, $response);
        $this->assertCount(2, $response[0]["result"]);

        foreach ($response[0]["result"] as $record) {
            $this->assertArrayHasKey("name", $record);
            $this->assertArrayHasKey("age", $record);
            $this->assertTrue($record["age"] >= 18);
        }

        $response = $db->queryRaw("SELECT * FROM person WHERE age >= 18; SELECT * FROM person WHERE age = 24");
        $this->assertIsArray($response);

        // First query
        $this->assertCount(2, $response);
        $this->assertCount(2, $response[0]["result"]);

        // Second query
        $this->assertCount(1, $response[1]["result"]);

        // First query
        $this->assertArrayHasKey("name", $response[0]["result"][0]);
        $this->assertArrayHasKey("age", $response[0]["result"][0]);

        // Second query
        $this->assertArrayHasKey("name", $response[1]["result"][0]);
        $this->assertArrayHasKey("age", $response[1]["result"][0]);

        $id = RecordId::create("person", "beau");
        $response = $db->delete($id);

        $this->assertIsArray($response);
        $this->assertInstanceOf(RecordId::class, $response["id"]);

        $id = RecordId::create("person", "beau");
        $response = $db->select($id);

        $this->assertInstanceOf(None::class, $response);

        $db->close();
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

        $db->close();
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

        $db->close();
    }

    public function testRelate(): void
    {
        // CHANGE: change "from" and "to" to StringRecordId. Currently, has issues with the StringRecordId.

        $db = $this->getDb();

        $from = RecordId::create("relate", "testA");
        $kind = Table::create("table");
        $to = RecordId::create("relate", "testB");

        [$response] = $db->relate($from, $kind, $to);

        $this->assertIsArray($response);

        $this->assertArrayHasKey("id", $response);
        $this->assertArrayHasKey("in", $response);
        $this->assertArrayHasKey("out", $response);

        // test with content data
        $data = ["a" => 1, "b" => 2];
        [$response] = $db->relate($from, $kind, $to, $data);

        $this->assertIsArray($response);

        $this->assertArrayHasKey("id", $response);
        $this->assertArrayHasKey("in", $response);
        $this->assertArrayHasKey("out", $response);

        $this->assertArrayHasKey("a", $response);
        $this->assertArrayHasKey("b", $response);

        $db->close();
    }

    public function testRun(): void
    {
        $db = $this->getDb();

        $response = $db->run(
            function: "fn::greet",
            params: ["Beau"]
        );

        $this->assertEquals("Hello, Beau!", $response);

        $db->close();
    }

    public function testFutureQuery(): void
    {
        $db = $this->getDb();

        $future = new Future("duration::years(time::now() - birthday) >= 18");
        $db->let("canDrive", $future);

        $response = $db->queryRaw('
            CREATE future_test
            SET
                birthday = <datetime> "2000-06-22",
                can_drive = $canDrive
        ');

        $this->assertIsArray($response);

        [$data] = $response;

        $this->assertArrayHasKey("result", $data);
        $this->assertArrayHasKey("time", $data);
        $this->assertArrayHasKey("status", $data);

        $this->assertEquals("OK", $data["status"]);

        $db->close();
    }
}