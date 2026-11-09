<?php

namespace SurrealDB\SDK\Query\Concerns;

use SurrealDB\SDK\Query\BoundQuery;

/**
 * Adds a `WHERE <condition>` modifier.
 */
trait HasCondition
{
    /** @var list<string|BoundQuery> */
    public private(set) array $conditions = [];

    /**
     * Filter affected records. Pass a raw SurrealQL string, or a
     * {@see BoundQuery} fragment to keep interpolated values parameterized.
     * Each call adds a condition, and a record must meet them all.
     */
    public function where(string|BoundQuery $cond): static
    {
        $this->conditions[] = $cond;

        return $this;
    }

    /**
     * Each condition goes in parentheses, the closing one on a line of its
     * own, so it cannot reach past its end: a trailing line comment stops
     * before the clauses that follow, and a `;` is a syntax error instead of
     * the start of another statement.
     */
    protected function applyWhere(BoundQuery $query): void
    {
        foreach ($this->conditions as $index => $cond) {
            $query->append($index === 0 ? ' WHERE (' : ' AND (')->append($cond)->append("\n)");
        }
    }
}
