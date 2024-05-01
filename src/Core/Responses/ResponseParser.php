<?php

namespace Surreal\Core\Responses;

use Beau\CborPHP\exceptions\CborException;
use Exception;
use InvalidArgumentException;
use JsonException;
use Surreal\Cbor\CBOR;
use Surreal\Curl\HttpContentType;

class ResponseParser
{
    /**
     * @throws JsonException|CborException
     * @throws Exception
     */
    public static function parse(HttpContentType $type, string $body)
    {
        return match ($type) {
            HttpContentType::JSON => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            HttpContentType::SURREAL, HttpContentType::CBOR => CBOR::decode($body),
            HttpContentType::UTF8 => $body,
            default => throw new InvalidArgumentException('Unsupported content type')
        };
    }
}