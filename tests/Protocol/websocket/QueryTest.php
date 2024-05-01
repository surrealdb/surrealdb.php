<?php

namespace protocol\websocket;

use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\RecordId;
use Surreal\Core\Client\SurrealWebsocket;
use Surreal\Core\Utils\SurrealPatch;
use Throwable;

class QueryTest extends TestCase
{
    private static SurrealWebsocket $db;

    /**
     * @throws \Exception
     */
    public static function setUpBeforeClass(): void
    {
        self::$db = new SurrealWebsocket(
            host: "ws://127.0.0.1:8000/rpc",
            target: ["namespace" => "test", "database" => "test"]
        );

        $token = self::$db->signin([
            "user" => "root",
            "pass" => "root"
        ]);

        self::assertIsString($token, "The token is not a string");
        self::assertTrue(self::$db->isConnected());

        self::$db->authenticate($token);

        parent::setUpBeforeClass();
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function testCRUD(): void
    {
        $created_person = self::$db->create("person:beaudh", [
            "name" => "Beau",
            "age" => 30
        ]);

        $this->assertIsArray($created_person, "The created person is not an array");
        $this->assertEquals(RecordId::class, $created_person["id"]::class);

        $this->assertEquals("Beau", $created_person["name"], "The created person's name is not Beau");
        $this->assertEquals(30, $created_person["age"], "The created person's age is not 30");

        $selected_person = self::$db->select("person:beaudh");

        $this->assertIsArray($selected_person, "The selected person is not an array");
        $this->assertArrayHasKey("id", $selected_person, "The selected person does not have an id");

        $this->assertEquals("Beau", $selected_person["name"], "The selected person's name is not Beau");
        $this->assertEquals(30, $selected_person["age"], "The selected person's age is not 30");

        $updated_person = self::$db->update("person:beaudh", [
            "age" => 31
        ]);

        $this->assertIsArray($updated_person, "The updated person is not an array");
        $this->assertArrayHasKey("id", $updated_person, "The updated person does not have an id");

        $this->assertArrayNotHasKey("name", $updated_person, "The deleted person's name is not empty");
        $this->assertEquals(31, $updated_person["age"], "The updated person's age is not 31");

        $deleted_person = self::$db->delete("person:beaudh");

        $this->assertIsArray($deleted_person, "The deleted person is not an array");
        $this->assertArrayHasKey("id", $deleted_person, "The deleted person does not have an id");

        $this->assertArrayNotHasKey("name", $deleted_person, "The deleted person's name is not empty");
        $this->assertEquals(31, $deleted_person["age"], "The deleted person's age is not 31");
    }

    /**
     * @throws Exception
     */
    public function testPatch(): void
    {
        $created_person = self::$db->create("person:beaudx", [
            "name" => "Beau",
            "age" => 30
        ]);

        $this->assertIsArray($created_person, "The created person is not an array");
        $this->assertArrayHasKey("id", $created_person, "The created person does not have an id");

        $patched_person = self::$db->patch("person:beaudx", [
            SurrealPatch::create("replace", "/name", "Beaudha")
        ], true);

        $this->assertIsArray($patched_person, "The patched person is not an array");

        foreach ($patched_person as $person) {
            $this->assertArrayHasKey("op", $person, "The patched person does not have an op");
            $this->assertArrayHasKey("path", $person, "The patched person does not have a path");
            $this->assertArrayHasKey("value", $person, "The patched person does not have a value");
        }

        $deleted_person = self::$db->delete("person:beaudx");

        $this->assertIsArray($deleted_person, "The deleted person is not an array");
        $this->assertArrayHasKey("id", $deleted_person, "The deleted person does not have an id");
        $this->assertArrayHasKey("name", $deleted_person, "The deleted person's name is not empty");
        $this->assertEquals(30, $deleted_person["age"], "The deleted person's age is not 30");
    }

    /**
     * @throws Exception
     */
    public function testMerge(): void
    {
        $created_person = self::$db->create("person:beaudzx", [
            "name" => "Beau",
            "age" => 30
        ]);

        $this->assertIsArray($created_person, "The created person is not an array");
        $this->assertArrayHasKey("id", $created_person, "The created person does not have an id");

        $merged_person = self::$db->merge("person:beaudzx", [
            "age" => 31
        ]);

        $this->assertIsArray($merged_person, "The merged person is not an array");
        $this->assertArrayHasKey("id", $merged_person, "The merged person does not have an id");

        $this->assertArrayHasKey("name", $merged_person, "The merged person does not have a name");
        $this->assertEquals("Beau", $merged_person["name"], "The merged person's name is not Beau");

        $this->assertEquals(31, $merged_person["age"], "The merged person's age is not 31");
    }

    /**
     * @throws Exception
     */
    public function testInsert(): void
    {
        $inserted_person = self::$db->insert("person", [
            ["name" => "Beau", "age" => 25],
            ["name" => "Julian", "age" => 24]
        ]);

        $this->assertIsArray($inserted_person, "The inserted persons is not an array");

        foreach ($inserted_person as $person) {
            $this->assertArrayHasKey("id", $person, "The inserted person does not have an id");
            $this->assertArrayHasKey("name", $person, "The inserted person does not have a name");
            $this->assertArrayHasKey("age", $person, "The inserted person does not have an age");
        }
    }

    /**
     * @throws Exception
     */
    public function testQuery(): void
    {
        $persons = self::$db->query("SELECT * FROM person");
        $this->assertIsArray($persons, "The persons is not an array");
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->close();
        self::assertFalse(self::$db->isConnected());

        parent::tearDownAfterClass();
    }
}