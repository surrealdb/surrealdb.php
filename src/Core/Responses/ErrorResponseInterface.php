<?php

namespace Surreal\Core\Responses;

use Surreal\Core\Curl\HttpContentFormat;

interface ErrorResponseInterface
{
    /**
     * @param mixed $data
     * @param HttpContentFormat $type
     * @param int $status
     * @return ResponseInterface|null
     */
    public static function tryFrom(mixed $data, HttpContentFormat $type, int $status): ?ResponseInterface;
}