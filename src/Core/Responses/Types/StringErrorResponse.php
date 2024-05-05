<?php

namespace Surreal\Core\Responses\Types;

use Surreal\Core\Curl\HttpContentFormat;
use Surreal\Core\Responses\ErrorResponseInterface;
use Surreal\Core\Responses\ResponseInterface;

readonly class StringErrorResponse implements ResponseInterface, ErrorResponseInterface
{
    public int $status;
    public string $error;

    public function __construct(mixed $data, int $status)
    {
        $this->status = $status;
        $this->error = $data;
    }

    public static function from(mixed $data, HttpContentFormat $type, int $status): ResponseInterface
    {
        return new self($data, $status);
    }

    /**
     * @param mixed $data
     * @param HttpContentFormat $type
     * @param int $status
     * @return ResponseInterface|null
     */
    public static function tryFrom(mixed $data, HttpContentFormat $type, int $status): ?StringErrorResponse
    {
        return match ($status) {
            200 => null,
            default => self::from($data, $type, $status)
        };
    }

    public function data(): mixed
    {
        return $this->error;
    }
}