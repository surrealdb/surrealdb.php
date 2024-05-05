<?php

namespace parsers;

use Beau\CborPHP\exceptions\CborException;
use JsonException;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Curl\HttpContentFormat;
use Surreal\Core\Responses\ResponseParser;

class ResponseParserTest extends TestCase
{
    /**
     * @throws CborException
     * @throws JsonException
     */
    public function testStringResponse(): void
    {
        $type = HttpContentFormat::UTF8;
        $body = "Hello, World!";

        $response = ResponseParser::parse($type, $body);
        $this->assertEquals($body, $response);
    }
}