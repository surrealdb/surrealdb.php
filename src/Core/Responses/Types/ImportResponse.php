<?php

namespace Surreal\Core\Responses\Types;

use Surreal\Core\Responses\ResponseInterface;
use Surreal\Curl\HttpContentType;
use Surreal\Exceptions\AuthException;

readonly class ImportResponse implements ResponseInterface
{
    public mixed $result;
    public int $status;

    public function __construct(mixed $data, int $status)
    {
        $this->result = $data;
        $this->status = $status;
    }

    /**
     * @throws AuthException
     */
    public static function from(mixed $data, HttpContentType $type, int $status): ResponseInterface
    {
        return match ([$status, $type]) {
            // TODO: implement the rest of the cases here.
            [401, HttpContentType::UTF8] => throw new AuthException($data),
            default => new self($data, $status),
        };
    }

    public function data(): mixed
    {
        return $this->result;
    }
}