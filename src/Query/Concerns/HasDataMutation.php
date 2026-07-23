<?php

namespace SurrealDB\Query\Concerns;

use SurrealDB\Enum\Mutation;
use SurrealDB\Query\BoundQuery;

/**
 * Shared storage + compilation for the data mutation keyword
 * (`CONTENT` / `MERGE` / `REPLACE` / `PATCH`). The payload is bound as a
 * parameter; each builder exposes only the public verbs valid for its statement.
 */
trait HasDataMutation
{
    public private(set) ?Mutation $mutation = null;
    public private(set) mixed $data = null;

    protected function mutate(Mutation $mutation, mixed $data): static
    {
        $this->mutation = $mutation;
        $this->data = $data;

        return $this;
    }

    protected function applyMutation(BoundQuery $query): void
    {
        if ($this->mutation === null) {
            return;
        }

        $query->append(' ' . $this->mutation->toSql() . ' ' . $query->bind($this->data));
    }
}
