<?php

namespace Surreal\Core\Results;

use Surreal\Core\Responses\ResponseInterface;
use Surreal\Core\Responses\Types\StringErrorResponse;
use Surreal\Core\Responses\Types\StringResponse;
use Surreal\Exceptions\SurrealException;

readonly class StringResult implements ResultInterface
{
    /**
     * @throws SurrealException
     */
    public static function from(ResponseInterface $response): mixed
    {
        return match ($response::class) {
            StringResponse::class => $response->data(),
            StringErrorResponse::class => throw new SurrealException($response->data(), $response->status),
            default => null
        };
    }
}