<?php

namespace SurrealDB\SDK\Exceptions;

use SurrealDB\SDK\Api\ApiResponse;
use function sprintf;

/** Thrown by {@see \SurrealDB\SDK\Query\ApiQuery::value()} calls when the endpoint answers with a non-2xx status. */
final class UnsuccessfulApiException extends SurrealException
{
    /**
     * @param ApiResponse<mixed> $response
     */
    public function __construct(
        public readonly string $path,
        public readonly string $method,
        public readonly ApiResponse $response,
    ) {
        parent::__construct(sprintf(
            'The %s %s request failed with status %d',
            strtoupper($method),
            $path,
            $response->status,
        ));
    }
}
