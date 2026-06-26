<?php

namespace SurrealDB\SDK\Query;

use SurrealDB\SDK\Contracts\QueryExecutor;
use SurrealDB\SDK\Enum\Output;
use SurrealDB\SDK\Query\Concerns\HasOutput;
use SurrealDB\SDK\Query\Concerns\HasTimeout;
use SurrealDB\SDK\Query\Concerns\HasVersion;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;

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
