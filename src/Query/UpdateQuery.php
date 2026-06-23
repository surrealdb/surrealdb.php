<?php

namespace SurrealDB\SDK\Query;

use SurrealDB\SDK\Contracts\QueryExecutor;
use SurrealDB\SDK\Enum\Mutation;
use SurrealDB\SDK\Query\Concerns\HasCondition;
use SurrealDB\SDK\Query\Concerns\HasDataMutation;
use SurrealDB\SDK\Query\Concerns\HasOutput;
use SurrealDB\SDK\Query\Concerns\HasTimeout;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;

/**
 * Fluent `UPDATE` builder.
 *
 * @template TResult
 * @extends QueryBuilder<TResult>
 */
final class UpdateQuery extends QueryBuilder
{
    use HasCondition;
    use HasDataMutation;
    use HasOutput;
    use HasTimeout;

    /**
     * @param RecordId<string>|Table<string>|string $what
     */
    public function __construct(
        ?QueryExecutor $executor,
        public private(set) RecordId|Table|string $what,
    ) {
        parent::__construct($executor);
    }

    /** @param array<string,mixed>|object $data */
    public function content(array|object $data): static
    {
        return $this->mutate(Mutation::CONTENT, $data);
    }

    /** @param array<string,mixed>|object $data */
    public function merge(array|object $data): static
    {
        return $this->mutate(Mutation::MERGE, $data);
    }

    /** @param array<string,mixed>|object $data */
    public function replace(array|object $data): static
    {
        return $this->mutate(Mutation::REPLACE, $data);
    }

    /** @param list<array<string,mixed>> $patches */
    public function patch(array $patches): static
    {
        return $this->mutate(Mutation::PATCH, $patches);
    }

    protected function build(): BoundQuery
    {
        $query = new BoundQuery('UPDATE ');
        $this->appendTarget($query, $this->what);
        $this->applyMutation($query);
        $this->applyWhere($query);
        $this->applyOutput($query);
        $this->applyTimeout($query);

        return $query;
    }
}
