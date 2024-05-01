<?php

namespace Utils;

use PHPUnit\Framework\TestCase;
use Surreal\Utils\ArrayHelper;

class ArrayHelperTest extends TestCase
{
    public function testIsAssoc(): void
    {
        $data1 = [];
        $isAssoc = ArrayHelper::isAssoc($data1);

        $this->assertFalse($isAssoc);

        $data2 = [1, 2, 3];
        $isAssoc = ArrayHelper::isAssoc($data2);

        $this->assertFalse($isAssoc);

        $data3 = ["key" => 1, "value" => 2];
        $isAssoc = ArrayHelper::isAssoc($data3);

        $this->assertTrue($isAssoc);

        $data4 = "";
        $isAssoc = ArrayHelper::isAssoc($data4);

        $this->assertFalse($isAssoc);
    }
}