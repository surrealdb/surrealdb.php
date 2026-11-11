<?php

namespace SurrealDB\Contracts;

use SurrealDB\Protocol\QueryChunk;
use SurrealDB\Query\BoundQuery;

/**
 * A {@see QueryExecutor} that can also hand back each statement's result as
 * it is read, failures included, for {@see \SurrealDB\Query\QueryBuilder::stream()}
 * and {@see \SurrealDB\Query\QueryBuilder::responses()}.
 */
interface StreamingQueryExecutor extends QueryExecutor
{
    /**
     * @return iterable<QueryChunk<mixed>>
     */
    public function stream(BoundQuery $query): iterable;
}
