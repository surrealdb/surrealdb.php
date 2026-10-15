<?php

namespace SurrealDB\Query\Concerns;

use SurrealDB\Enum\Output;
use SurrealDB\Query\BoundQuery;

/**
 * Adds a `RETURN <output>` modifier (NONE / NULL / DIFF / BEFORE / AFTER).
 */
trait HasOutput
{
    public private(set) ?Output $output = null;

    /**
     * Configure what the statement returns.
     */
    public function output(Output $output): static
    {
        $this->output = $output;

        return $this;
    }

    protected function applyOutput(BoundQuery $query): void
    {
        if ($this->output === null) {
            return;
        }

        $query->append(' RETURN ' . $this->output->toSql());
    }
}
