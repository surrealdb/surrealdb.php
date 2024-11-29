<?php

namespace v2\query;

use PHPUnit\Framework\TestCase;

class FutureQueryTest extends TestCase
{
    public function testFutureQuery(): void
    {
        $db = $this->getDb();

        $future = new Future("duration::years(time::now() - birthday) >= 18");
        $db->let("canDrive", $future);

        $response = $db->queryRaw('
            CREATE future_test
            SET
                birthday = time::now(),
                can_drive = true
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