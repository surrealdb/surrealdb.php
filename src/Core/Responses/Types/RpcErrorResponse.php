<?php

namespace Surreal\Core\Responses\Types;

use InvalidArgumentException;
use Surreal\Core\Responses\ErrorResponseInterface;
use Surreal\Core\Responses\ResponseInterface;
use Surreal\Curl\HttpContentType;
use Surreal\Exceptions\SurrealException;

readonly class RpcErrorResponse implements ResponseInterface, ErrorResponseInterface
{
    public string $error;
    public int $status;

    public function __construct(mixed $data, int $code)
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException("Invalid response data type provided");
        }

        $this->error = $data["error"]["message"];
        $this->status = $code;
    }

    public static function from(mixed $data, HttpContentType $type, int $status): RpcErrorResponse
    {
        return new self($data, $status);
    }

    /**
     * @throws SurrealException
     */
    public static function tryFrom(mixed $data, HttpContentType $type, int $status): ?ResponseInterface
    {
        if ($status !== 200) {
            return self::from($data, $type, $status);
        }

        throw new SurrealException("Unknown error response has been returned.");
    }

    public function data(): mixed
    {
        return $this->error;
    }
}