<?php

namespace SurrealDB\SDK\Query;

use SurrealDB\SDK\Contracts\QueryExecutor;
use SurrealDB\SDK\Query\Concerns\HasOutput;
use SurrealDB\SDK\Query\Concerns\HasTimeout;
use SurrealDB\SDK\Query\Concerns\HasVersion;
use SurrealDB\SDK\Types\Table;

/**
 * Fluent `INSERT` builder. The payload (one record or a list of records) is
 * bound as a parameter; the optional table is escaped inline.
 *
 * @template TResult
 * @extends QueryBuilder<TResult>
 */
final class InsertQuery extends QueryBuilder
{
    use HasOutput;
    use HasTimeout;
    use HasVersion;

    public private(set) bool $relation = false;
    public private(set) bool $ignore = false;

    /**
     * @param Table<string>|null $table
     * @param array<string,mixed>|list<array<string,mixed>>|object $data
     */
    public function __construct(
        ?QueryExecutor $executor,
        public private(set) ?Table $table,
        public private(set) array|object $data,
    ) {
        parent::__construct($executor);
    }

    /**
     * Insert into a relation table rather than a regular table (`INSERT RELATION`).
     */
    public function relation(): static
    {
        $this->relation = true;

        return $this;
    }

    /**
     * Ignore records that already exist (`INSERT IGNORE`).
     */
    public function ignore(): static
    {
        $this->ignore = true;

        return $this;
    }

    protected function build(): BoundQuery
    {
        $query = new BoundQuery('INSERT');

        if ($this->relation) {
            $query->append(' RELATION');
        }

        if ($this->ignore) {
            $query->append(' IGNORE');
        }

        if ($this->table !== null) {
            $query->append(' INTO ' . $this->thing($this->table));
        }

        $query->append(' ' . $query->bind($this->data));

        $this->applyOutput($query);
        $this->applyTimeout($query);
        $this->applyVersion($query);

        return $query;
    }
}
