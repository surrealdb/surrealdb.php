<?php

namespace utils;

use PHPUnit\Framework\TestCase;
use Surreal\Core\Utils\Helpers;

class ArrayHelperTest extends TestCase
{
    public function testIsAssoc(): void
    {
        $data1 = [];
        $isAssoc = Helpers::isAssoc($data1);

        $this->assertFalse($isAssoc);

        $data2 = [1, 2, 3];
        $isAssoc = Helpers::isAssoc($data2);

        $this->assertFalse($isAssoc);

        $data3 = ["key" => 1, "value" => 2];
        $isAssoc = Helpers::isAssoc($data3);

        $this->assertTrue($isAssoc);

        $data4 = "";
        $isAssoc = Helpers::isAssoc($data4);

        $this->assertFalse($isAssoc);
    }
}