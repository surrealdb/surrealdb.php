<?php

namespace methods;

use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\Record\RecordId;
use Surreal\Surreal;

class InsertRelationTest extends TestCase
{
    private function getDb(string $protocol): Surreal
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

    private function testInsertRelation(string $protocol)
    {
        $db = $this->getDb($protocol);

        $recordA = RecordId::create("insert_relation_a", "test");
        $recordB = RecordId::create("insert_relation_b", "test");

        // Test a single insertion
        [$response] = $db->insertRelation("insert_relation", [
            "in" => $recordA,
            "out" => $recordB,
            "since" => new \DateTime('now')
        ]);

        $this->assertIsArray($response);

        $this->assertArrayHasKey("in", $response);
        $this->assertArrayHasKey("out", $response);
        $this->assertArrayHasKey("since", $response);

        $this->assertInstanceOf(RecordId::class, $response["in"]);
        $this->assertInstanceOf(RecordId::class, $response["out"]);
        $this->assertInstanceOf(\DateTime::class, $response["since"]);

        // Test multiple insertions
        $response = $db->insertRelation("insert_relation", [
            [
                "in" => $recordA,
                "out" => $recordB,
                "since" => new \DateTime('now')
            ],
            [
                "in" => $recordB,
                "out" => $recordA,
                "since" => new \DateTime('now')
            ]
        ]);

        $this->assertIsArray($response);
        $this->assertTrue(count($response) === 2);

        foreach ($response as $item) {
            $this->assertArrayHasKey("in", $item);
            $this->assertArrayHasKey("out", $item);
            $this->assertArrayHasKey("since", $item);

            $this->assertInstanceOf(RecordId::class, $item["in"]);
            $this->assertInstanceOf(RecordId::class, $item["out"]);
            $this->assertInstanceOf(\DateTime::class, $item["since"]);
        }
    }

    public function testInsertRelationHTTP(): void
    {
        $this->testInsertRelation("http");
    }

    public function testInsertRelationWS(): void
    {
        $this->testInsertRelation("ws");
    }
}