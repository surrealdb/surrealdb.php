<?php

namespace SurrealDB\Exceptions;

use function is_string;

/** Thrown by {@see \SurrealDB\GraphQl\GraphQlResponse::dataOrThrow()} when a GraphQL request reported errors. */
final class GraphQlException extends SurrealException
{
    /**
     * @param list<array<string,mixed>> $errors
     */
    public function __construct(public readonly array $errors)
    {
        $first = $errors[0]['message'] ?? null;

        parent::__construct(is_string($first) ? $first : 'The GraphQL request failed');
    }
}
