<?php

namespace SurrealDB\SDK\Query;

use SurrealDB\SDK\Contracts\QueryExecutor;
use SurrealDB\SDK\Enum\Mutation;
use SurrealDB\SDK\Query\Concerns\HasDataMutation;
use SurrealDB\SDK\Query\Concerns\HasOutput;
use SurrealDB\SDK\Query\Concerns\HasTimeout;
use SurrealDB\SDK\Query\Concerns\HasVersion;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;

/**
 * Fluent `CREATE` builder.
 *
 * @template TResult
 * @extends QueryBuilder<TResult>
 */
final class CreateQuery extends QueryBuilder
{
    use HasDataMutation;
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
    }

    /**
     * Set the record contents (`CONTENT`).
     *
     * @param array<string,mixed>|object $data
     */
    public function content(array|object $data): static
    {
        return $this->mutate(Mutation::CONTENT, $data);
    }

    /**
     * Apply a JSON patch (`PATCH`).
     *
     * @param list<array<string,mixed>> $patches
     */
    public function patch(array $patches): static
    {
        return $this->mutate(Mutation::PATCH, $patches);
    }

    protected function build(): BoundQuery
    {
        $query = new BoundQuery('CREATE ');
        $this->appendTarget($query, $this->what);
        $this->applyMutation($query);
        $this->applyOutput($query);
        $this->applyTimeout($query);
        $this->applyVersion($query);

        return $query;
    }
}
