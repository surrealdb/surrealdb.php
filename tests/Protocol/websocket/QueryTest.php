<?php

namespace protocol\websocket;

use Beau\CborPHP\exceptions\CborException;
use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\RecordId;
use Surreal\Cbor\Types\Table;
use Surreal\Exceptions\SurrealException;
use Surreal\Surreal;
use Throwable;

class QueryTest extends TestCase
{
    /**
     * @throws CborException|SurrealException|Exception
     */
    private function getDb(): Surreal
    {
        $db = new Surreal();
        $db->connect("ws://localhost:8000/rpc", [
            "namespace" => "test",
            "database" => "test"
        ]);

        $jwt = $db->signin([
            "user" => "root",
            "pass" => "root"
        ]);

        $this->assertIsString($jwt, "Token is not a string");
        $this->assertTrue($db->status() === 200);

        $db->authenticate($jwt);

        return $db;
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function testCRUD(): void
    {
        $db = $this->getDb();

        $id = RecordId::create("person", "beaudh");
        $created_person = $db->create($id, ["name" => "Beau", "age" => 30]);

        $this->assertIsArray($created_person, "The created person is not an array");
        $this->assertEquals(RecordId::class, $created_person["id"]::class);

        $this->assertEquals("Beau", $created_person["name"], "The created person's name is not Beau");
        $this->assertEquals(30, $created_person["age"], "The created person's age is not 30");

        $id = RecordId::create("person", "beaudh");
        $selected_person = $db->select($id);

        $this->assertIsArray($selected_person, "The selected person is not an array");
        $this->assertArrayHasKey("id", $selected_person, "The selected person does not have an id");

        $this->assertEquals("Beau", $selected_person["name"], "The selected person's name is not Beau");
        $this->assertEquals(30, $selected_person["age"], "The selected person's age is not 30");

        $id = RecordId::create("person", "beaudh");
        $updated_person = $db->update($id, ["age" => 31]);

        $this->assertIsArray($updated_person, "The updated person is not an array");
        $this->assertArrayHasKey("id", $updated_person, "The updated person does not have an id");

        $this->assertArrayNotHasKey("name", $updated_person, "The deleted person's name is not empty");
        $this->assertEquals(31, $updated_person["age"], "The updated person's age is not 31");

        $id = RecordId::create("person", "beaudh");
        $deleted_person = $db->delete($id);

        $this->assertIsArray($deleted_person, "The deleted person is not an array");
        $this->assertArrayHasKey("id", $deleted_person, "The deleted person does not have an id");

        $this->assertArrayNotHasKey("name", $deleted_person, "The deleted person's name is not empty");
        $this->assertEquals(31, $deleted_person["age"], "The deleted person's age is not 31");

        $db->disconnect();
    }

    /**
     * @throws Exception
     */
    public function testPatch(): void
    {
        $id = RecordId::create("person", "beaudx");
        $db = $this->getDb();

        $created_person = $db->create($id, ["name" => "Beau", "age" => 30]);

        $this->assertIsArray($created_person, "The created person is not an array");
        $this->assertArrayHasKey("id", $created_person, "The created person does not have an id");

        $patched_person = $db->patch($id, [
            ["op" => "replace", "path" => "/name", "value" => "Beaudha"],
        ], true);

        $this->assertIsArray($patched_person, "The patched person is not an array");

        foreach ($patched_person as $person) {
            $this->assertArrayHasKey("op", $person, "The patched person does not have an op");
            $this->assertArrayHasKey("path", $person, "The patched person does not have a path");
            $this->assertArrayHasKey("value", $person, "The patched person does not have a value");
        }

        $deleted_person = $db->delete($id);

        $this->assertIsArray($deleted_person, "The deleted person is not an array");
        $this->assertArrayHasKey("id", $deleted_person, "The deleted person does not have an id");
        $this->assertArrayHasKey("name", $deleted_person, "The deleted person's name is not empty");
        $this->assertEquals(30, $deleted_person["age"], "The deleted person's age is not 30");

        $db->disconnect();
    }

    /**
     * @throws Exception
     */
    public function testMerge(): void
    {
        $db = $this->getDb();

        $id = RecordId::create("person", "beaudzx");
        $created_person = $db->create($id, ["name" => "Beau", "age" => 30]);

        $this->assertIsArray($created_person, "The created person is not an array");
        $this->assertArrayHasKey("id", $created_person, "The created person does not have an id");

        $merged_person = $db->merge($id, ["age" => 31]);

        $this->assertIsArray($merged_person, "The merged person is not an array");
        $this->assertArrayHasKey("id", $merged_person, "The merged person does not have an id");

        $this->assertArrayHasKey("name", $merged_person, "The merged person does not have a name");
        $this->assertEquals("Beau", $merged_person["name"], "The merged person's name is not Beau");

        $this->assertEquals(31, $merged_person["age"], "The merged person's age is not 31");

        $db->disconnect();
    }

    /**
     * @throws Exception
     */
    public function testInsert(): void
    {
        $db = $this->getDb();

        $inserted_person = $db->insert("person", [
            ["name" => "Beau", "age" => 25],
            ["name" => "Julian", "age" => 24]
        ]);

        $this->assertIsArray($inserted_person, "The inserted persons is not an array");

        foreach ($inserted_person as $person) {
            $this->assertArrayHasKey("id", $person, "The inserted person does not have an id");
            $this->assertArrayHasKey("name", $person, "The inserted person does not have a name");
            $this->assertArrayHasKey("age", $person, "The inserted person does not have an age");
        }

        $db->disconnect();
    }

    /**
     * @throws Exception
     */
    public function testQuery(): void
    {
        $db = $this->getDb();
        $persons = $db->query("SELECT * FROM person");
        $this->assertIsArray($persons, "The persons is not an array");
    }

    public function testRelate(): void
    {
        // CHANGE: change "from" and "to" to StringRecordId. Currently, has issues with the StringRecordId.

        $db = $this->getDb();

        $from = RecordId::create("relate", "testA");
        $kind = Table::create("table");
        $to = RecordId::create("relate", "testB");

        $response = $db->relate($from, $kind, $to);

        $this->assertIsArray($response);

        $this->assertArrayHasKey("id", $response);
        $this->assertArrayHasKey("in", $response);
        $this->assertArrayHasKey("out", $response);

        // test with content data
        $data = ["a" => 1, "b" => 2];
        $response = $db->relate($from, $kind, $to, $data);

        $this->assertIsArray($response);

        $this->assertArrayHasKey("id", $response);
        $this->assertArrayHasKey("in", $response);
        $this->assertArrayHasKey("out", $response);

        $this->assertArrayHasKey("a", $response);
        $this->assertArrayHasKey("b", $response);

        $db->disconnect();
    }

    public function testRun(): void
    {
        $db = $this->getDb();

        $response = $db->run("fn::greet", "1.0.0", "Beau");
        $this->assertEquals("Hello, Beau!", $response);

        $db->disconnect();
    }
}