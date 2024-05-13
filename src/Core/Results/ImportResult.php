<?php

namespace Surreal\Core\Results;

use Surreal\Core\Responses\ResponseInterface;
use Surreal\Core\Responses\Types\ImportErrorResponse;
use Surreal\Core\Responses\Types\ImportResponse;
use Surreal\Exceptions\SurrealException;
use Surreal\Core\Utils\Helpers;

final class ImportResult implements ResultInterface
{
    /**
     * @throws SurrealException
     */
    public static function from(ResponseInterface $response): mixed
    {
        $data = $response->data();
        $isAssoc = Helpers::isAssoc($data);

        if(!$isAssoc) {
            return $data;
        }

        return match($response::class) {
            ImportResponse::class => $response->data(),
            ImportErrorResponse::class => throw new SurrealException($response->data(), $response->status),
            default => throw new SurrealException('Unknown response type')
        };
    }
}