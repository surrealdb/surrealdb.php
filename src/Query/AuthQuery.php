<?php

namespace SurrealDB\Query;

/**
 * Fluent builder that selects the currently-authenticated record by reading the
 * [`$auth`](https://surrealdb.com/docs/surrealql/parameters#auth) parameter.
 *
 * @template TResult
 * @extends QueryBuilder<TResult>
 */
final class AuthQuery extends QueryBuilder
{
    protected function build(): BoundQuery
    {
        return new BoundQuery('SELECT * FROM ONLY $auth');
    }
}
