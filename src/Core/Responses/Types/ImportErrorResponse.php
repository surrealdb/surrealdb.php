<?php

namespace Surreal\Core\Responses\Types;

use InvalidArgumentException;
use Surreal\Core\Responses\ErrorResponseInterface;
use Surreal\Core\Responses\ResponseInterface;
use Surreal\Curl\HttpContentType;

readonly class ImportErrorResponse implements ResponseInterface, ErrorResponseInterface
{
    public int $status;
    public string $details;
    public string $description;
    public string $information;

    public function __construct(mixed $data)
    {
        if(!is_array($data)) {
            throw new InvalidArgumentException("Invalid response data type provided");
        }

        $this->status = $data["code"];
        $this->details = $data["details"];
        $this->description = $data["description"];
        $this->information = $data["information"];
    }

    public static function tryFrom(mixed $data, HttpContentType $type, int $status): ?ResponseInterface
    {
        if($status !== 200) {
            return self::from($data, $type, $status);
        }

        return null;
    }

    public static function from(mixed $data, HttpContentType $type, int $status): ImportErrorResponse
    {
        return new self($data);
    }

    public function data(): string
    {
        return $this->information;
    }
}