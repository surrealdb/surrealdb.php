<?php

namespace SurrealDB\SDK\Query;

use SurrealDB\SDK\Exceptions\ServerException;

/**
 * The outcome of one statement, successful or not, as returned by
 * {@see QueryBuilder::responses()}. Mirrors the JS SDK's `QueryResponse`.
 *
 * @template T
 */
final readonly class QueryResponse
{
    /**
     * @param T|null $result
     */
    public function __construct(
        public bool $success,
        public mixed $result = null,
        public ?ServerException $error = null,
        /** How long the statement ran, as reported by the server. */
        public ?string $time = null,
        /** The statement type the server reported (`live`, `kill` or `other`), if any. */
        public ?string $type = null,
    ) {}

    /**
     * @return T|null
     */
    public function resultOrThrow(): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->result;
    }
}
