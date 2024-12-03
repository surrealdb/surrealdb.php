<?php

namespace core\cbor;

use PHPUnit\Framework\TestCase;
use Surreal\Cbor\Types\Future;

class FutureTest extends TestCase
{
    public function testToString()
    {
        $future = new Future("{ time::now() }");
        $this->assertEquals("<future> { time::now() }", $future->__toString());
        $this->assertEquals("<future> { time::now() }", $future->toString());
    }

    public function testJsonSerialize()
    {
        $future = new Future("{ time::now() }");
        $this->assertEquals("<future> { time::now() }", $future->jsonSerialize());
    }
}