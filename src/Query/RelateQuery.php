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
 * {@see Table}). A record range is refused at either end: select the records
 * and pass their ids.
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

    protected function result(mixed $result): mixed
    {
        /** @var TResult */
        return $this->oneRecordResult($result, !$this->isMultiple());
    }

    protected function build(): BoundQuery
    {
        $isMultiple = $this->isMultiple();

        if ($isMultiple && $this->edge instanceof RecordId) {
            throw new ExpressionException('Edge must be a table when relating multiple records');
        }

        // SurrealDB 3.x and 4.x make one edge to a range rather than one per
        // record in it, and 2.x cannot parse it.
        foreach ([$this->from, $this->to] as $end) {
            foreach (is_array($end) ? $end : [$end] as $record) {
                if (self::isRange($record)) {
                    throw new ExpressionException(
                        'relate() relates records, not a record range: select the records and pass their ids.',
                    );
                }
            }
        }

        $query = new BoundQuery('RELATE ');

        if (!$isMultiple && $this->takesOnly()) {
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

    /**
     * Whether either end is a list, so the statement makes many edges.
     */
    private function isMultiple(): bool
    {
        return is_array($this->from) || is_array($this->to);
    }
}
