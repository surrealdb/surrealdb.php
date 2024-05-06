<?php

namespace Surreal\Core\Responses;

use Beau\CborPHP\exceptions\CborException;
use Exception;
use InvalidArgumentException;
use JsonException;
use Surreal\Cbor\CBOR;
use Surreal\Core\Curl\HttpContentFormat;

class ResponseParser
{
    /**
     * @throws JsonException|CborException|InvalidArgumentException
     * @throws Exception
     */
    public static function parse(HttpContentFormat $type, string $body)
    {
        return match ($type) {
            HttpContentFormat::JSON => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            HttpContentFormat::SURREAL, HttpContentFormat::CBOR => CBOR::decode($body),
            HttpContentFormat::UTF8 => $body,
            default => throw new InvalidArgumentException('Unsupported content type')
        };
    }
}