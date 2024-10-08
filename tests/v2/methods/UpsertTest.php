<?php

namespace v2\methods;

use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\Record\RecordId;
use Surreal\Cbor\Types\Record\StringRecordId;
use Surreal\Surreal;

class UpsertTest extends TestCase
{
    private function getDB(string $protocol): Surreal
    {
        $db = new Surreal();

        $url = match ($protocol) {
            "http" => "http://localhost:8000",
            "ws" => "ws://localhost:8000/rpc",
        };

        $db->connect($url, [
            "namespace" => "test",
            "database" => "test"
        ]);

        $db->signin([
            "user" => "root",
            "pass" => "root"
        ]);

        return $db;
    }

    public function testUpsertHTTP(): void
    {
        $db = $this->getDB("http");
        $record = StringRecordId::create("upsert:test");

        $response = $db->upsert($record, ["x" => 1]);

        $this->assertIsArray($response);

        $this->assertArrayHasKey("x", $response);
        $this->assertArrayHasKey("id", $response);

        $this->assertInstanceOf(RecordId::class, $response["id"]);
        $this->assertTrue(is_int($response["x"]));
    }

    public function testUpsertWS(): void
    {
        $db = $this->getDB("ws");
        $record = StringRecordId::create("upsert:test");

        $response = $db->upsert($record, ["x" => 1]);

        $this->assertIsArray($response);

        $this->assertArrayHasKey("x", $response);
        $this->assertArrayHasKey("id", $response);

        $this->assertInstanceOf(RecordId::class, $response["id"]);
        $this->assertTrue(is_int($response["x"]));
    }
}