<?php

namespace SurrealDB\SDK\Query\Concerns;

use SurrealDB\SDK\Query\BoundQuery;

/**
 * Adds a `WHERE <condition>` modifier.
 */
trait HasCondition
{
    public private(set) string|BoundQuery|null $cond = null;

    /**
     * Filter affected records. Pass a raw SurrealQL string, or a
     * {@see BoundQuery} fragment to keep interpolated values parameterized.
     */
    public function where(string|BoundQuery $cond): static
    {
        $this->cond = $cond;

        return $this;
    }

    protected function applyWhere(BoundQuery $query): void
    {
        if ($this->cond === null) {
            return;
        }

        $query->append(' WHERE ')->append($this->cond);
    }
}
