<?php

namespace Surreal\Core\Responses;

use Surreal\Curl\HttpContentType;

interface ErrorResponseInterface
{
    /**
     * @param mixed $data
     * @param HttpContentType $type
     * @param int $status
     * @return ResponseInterface|null
     */
    public static function tryFrom(mixed $data, HttpContentType $type, int $status): ?ResponseInterface;
}