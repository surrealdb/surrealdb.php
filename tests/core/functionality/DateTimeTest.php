<?php

namespace core\functionality;

use DateTime;
use Exception;
use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\Record\RecordId;
use Surreal\Surreal;

final class DateTimeTest extends TestCase
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
    public function testDateTime(): void
    {
        $db = $this->getDb();
        $dateTime = new DateTime("2025-03-31 08:10:38.821000");

        [[$record]] = $db->query("SELECT VALUE timestamp FROM dates:1");

        $this->assertEquals($dateTime, $record);
    }
}