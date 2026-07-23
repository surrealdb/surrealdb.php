<?php

namespace SurrealDB\Query;

use SurrealDB\Contracts\QueryExecutor;
use SurrealDB\Query\Concerns\HasCondition;
use SurrealDB\Query\Concerns\HasTimeout;
use SurrealDB\Query\Concerns\HasVersion;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\Table;

/**
 * Fluent `SELECT` builder.
 *
 * @template TResult
 * @extends QueryBuilder<TResult>
 */
final class SelectQuery extends QueryBuilder
{
    use HasCondition;
    use HasTimeout;
    use HasVersion;

    /** @var list<string> */
    public private(set) array $fields = [];
    public private(set) ?string $selection = null;
    public private(set) ?int $start = null;
    public private(set) ?int $limit = null;
    /** @var list<string> */
    public private(set) array $fetch = [];

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
     * Project only the given fields (`SELECT type::fields([...])`).
     */
    public function fields(string ...$fields): static
    {
        $this->fields = array_values($fields);
        $this->selection = 'fields';

        return $this;
    }

    /**
     * Return the value of a single field directly (`SELECT VALUE`).
     */
    public function value(string $field): static
    {
        $this->fields = [$field];
        $this->selection = 'value';

        return $this;
    }

    public function start(int $start): static
    {
        $this->start = $start;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Resolve the given record-link fields (`FETCH`).
     */
    public function fetch(string ...$fields): static
    {
        $this->fetch = array_values($fields);

        return $this;
    }

    protected function build(): BoundQuery
    {
        $query = new BoundQuery('SELECT');

        match ($this->selection) {
            'fields' => $query->append(' type::fields(' . $query->bind($this->fields) . ')'),
            'value' => $query->append(' VALUE type::field(' . $query->bind($this->fields[0]) . ')'),
            default => $query->append(' *'),
        };

        $query->append(' FROM ');
        $this->appendTarget($query, $this->what);

        $this->applyWhere($query);

        if ($this->start !== null) {
            $query->append(' START ' . $query->bind($this->start));
        }

        if ($this->limit !== null) {
            $query->append(' LIMIT ' . $query->bind($this->limit));
        }

        if ($this->fetch !== []) {
            $query->append(' FETCH type::fields(' . $query->bind($this->fetch) . ')');
        }

        $this->applyTimeout($query);
        $this->applyVersion($query);

        return $query;
    }
}
