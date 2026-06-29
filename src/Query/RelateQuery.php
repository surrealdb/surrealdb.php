<?php

namespace SurrealDB\SDK\Query;

use SurrealDB\SDK\Contracts\QueryExecutor;
use SurrealDB\SDK\Enum\Mutation;
use SurrealDB\SDK\Exceptions\ExpressionException;
use SurrealDB\SDK\Query\Concerns\HasDataMutation;
use SurrealDB\SDK\Query\Concerns\HasOutput;
use SurrealDB\SDK\Query\Concerns\HasTimeout;
use SurrealDB\SDK\Query\Concerns\HasVersion;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;
use function is_array;

/**
 * Fluent `RELATE` builder, creating graph edges between records. Pass arrays of
 * record IDs for `from`/`to` to relate many at once (the edge must then be a
 * {@see Table}).
 *
 * @template TResult
 * @extends QueryBuilder<TResult>
 */
final class RelateQuery extends QueryBuilder
{
    use HasDataMutation;
    use HasOutput;
    use HasTimeout;
    use HasVersion;

    public private(set) bool $unique = false;

    /**
     * @param RecordId<string>|list<RecordId<string>> $from
     * @param RecordId<string>|Table<string> $edge
     * @param RecordId<string>|list<RecordId<string>> $to
     */
    public function __construct(
        ?QueryExecutor $executor,
        public private(set) RecordId|array $from,
        public private(set) RecordId|Table $edge,
        public private(set) RecordId|array $to,
    ) {
        parent::__construct($executor);
    }

    /**
     * Enforce a unique relationship (advisory; retained for API parity).
     */
    public function unique(): static
    {
        $this->unique = true;

        return $this;
    }

    /**
     * Store data on the edge record (`CONTENT`).
     *
     * @param array<string,mixed>|object $data
     */
    public function content(array|object $data): static
    {
        return $this->mutate(Mutation::CONTENT, $data);
    }

    protected function build(): BoundQuery
    {
        $isMultiple = is_array($this->from) || is_array($this->to);

        if ($isMultiple && $this->edge instanceof RecordId) {
            throw new ExpressionException('Edge must be a table when relating multiple records');
        }

        $query = new BoundQuery('RELATE ');

        if (!$isMultiple) {
            $query->append('ONLY ');
        }

        $query->append(
            $this->thing($this->from) . '->' . $this->thing($this->edge) . '->' . $this->thing($this->to),
        );

        $this->applyMutation($query);
        $this->applyOutput($query);
        $this->applyTimeout($query);
        $this->applyVersion($query);

        return $query;
    }
}
