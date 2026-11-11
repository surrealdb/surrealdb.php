<?php

namespace SurrealDB\Query\Frame;

use SurrealDB\Exceptions\ServerException;

/**
 * A statement failed. Streaming carries on with the next statement.
 *
 * @template T
 * @extends Frame<T>
 */
final readonly class ErrorFrame extends Frame
{
    public function __construct(
        int $query,
        public ServerException $error,
        /** How long the statement ran, as reported by the server. */
        public ?string $time = null,
    ) {
        parent::__construct($query);
    }
}
