<?php

namespace SurrealDB\SDK\Contracts;

use SurrealDB\SDK\Query\BoundQuery;

/**
 * The terminal execution hook implemented by {@see \SurrealDB\SDK\Surreal}.
 * The fluent query builders (companion plan) depend only on this contract,
 * decoupling them from the networking layer.
 */
interface QueryExecutor
{
    /**
     * Execute a bound query, returning one result per statement.
     *
     * @return list<mixed>
     */
    public function query(BoundQuery $query): array;
}
