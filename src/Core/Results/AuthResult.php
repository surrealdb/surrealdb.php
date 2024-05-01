<?php

namespace Surreal\Core\Results;

use Surreal\Core\Responses\ResponseInterface;
use Surreal\Core\Responses\Types\RpcErrorResponse;
use Surreal\Core\Responses\Types\RpcResponse;
use Surreal\Exceptions\SurrealException;

class AuthResult implements ResultInterface
{
    /**
     * @throws SurrealException
     */
    public static function from(ResponseInterface $response): mixed
    {
        return match($response::class) {
            RpcResponse::class => $response->data(),
            RpcErrorResponse::class => throw new SurrealException($response->data(), $response->status),
            default => throw new SurrealException('Unknown response type')
        };
    }
}