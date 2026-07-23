<?php

namespace SurrealDB\Query;

use SurrealDB\Contracts\QueryExecutor;
use SurrealDB\Enum\Output;
use SurrealDB\Query\Concerns\HasOutput;
use SurrealDB\Query\Concerns\HasTimeout;
use SurrealDB\Query\Concerns\HasVersion;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\Table;

/**
 * Fluent `DELETE` builder. Defaults to `RETURN BEFORE` so deleted records are
 * returned to the caller, mirroring the JS SDK.
 *
 * @template TResult
 * @extends QueryBuilder<TResult>
 */
final class DeleteQuery extends QueryBuilder
{
    use HasOutput;
    use HasTimeout;
    use HasVersion;

    /**
     * @param RecordId<string>|Table<string>|string $what
     */
    public function __construct(
        ?QueryExecutor $executor,
        public private(set) RecordId|Table|string $what,
    ) {
        parent::__construct($executor);
        $this->output = Output::BEFORE;
    }

    protected function build(): BoundQuery
    {
        $query = new BoundQuery('DELETE ');
        $this->appendTarget($query, $this->what);
        $this->applyOutput($query);
        $this->applyTimeout($query);
        $this->applyVersion($query);

        return $query;
    }
}
