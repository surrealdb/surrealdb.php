<?php

namespace SurrealDB\Query\Concerns;

use SurrealDB\Enum\Output;
use SurrealDB\Query\BoundQuery;
use SurrealDB\Types\None;

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

    /**
     * Whether a statement on one record is sent with `ONLY`. SurrealDB 2.x
     * refuses `ONLY` with `RETURN NONE` ("Expected a single result output
     * when using the ONLY keyword"), so that statement is sent without it,
     * and {@see self::oneRecordResult()} gives back what 3.x and later
     * return with it.
     */
    protected function takesOnly(): bool
    {
        return $this->output !== Output::NONE;
    }

    /**
     * The result of a statement on one record (`$oneRecord`). Sent without
     * `ONLY` for `RETURN NONE`, it returns an empty list; this gives back the
     * NONE (null as JSON) that SurrealDB 3.x and later return with `ONLY`,
     * so the result is the same on every version.
     */
    protected function oneRecordResult(mixed $result, bool $oneRecord): mixed
    {
        if ($oneRecord && !$this->takesOnly() && $result === []) {
            return $this->json ? null : None::instance();
        }

        return $result;
    }
}
