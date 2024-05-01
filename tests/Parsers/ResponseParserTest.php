<?php

namespace parsers;

use Beau\CborPHP\exceptions\CborException;
use JsonException;
use PHPUnit\Framework\TestCase;
use Surreal\Core\Responses\ResponseParser;
use Surreal\Curl\HttpContentType;

class ResponseParserTest extends TestCase
{
    /**
     * @throws CborException
     * @throws JsonException
     */
    public function testStringResponse(): void
    {
        $type = HttpContentType::UTF8;
        $body = "Hello, World!";

        $response = ResponseParser::parse($type, $body);
        $this->assertEquals($body, $response);
    }
}