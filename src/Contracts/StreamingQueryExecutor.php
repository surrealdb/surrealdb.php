<?php

namespace SurrealDB\SDK\Contracts;

use SurrealDB\SDK\Protocol\QueryChunk;
use SurrealDB\SDK\Query\BoundQuery;

/**
 * A {@see QueryExecutor} that can also hand back each statement's result as
 * it is read, failures included, for {@see \SurrealDB\SDK\Query\QueryBuilder::stream()}
 * and {@see \SurrealDB\SDK\Query\QueryBuilder::responses()}.
 */
interface StreamingQueryExecutor extends QueryExecutor
{
    /**
     * @return iterable<QueryChunk<mixed>>
     */
    public function stream(BoundQuery $query): iterable;
}
