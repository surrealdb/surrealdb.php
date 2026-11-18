<?php

namespace SurrealDB\Query;

use SurrealDB\Contracts\QueryExecutor;

/**
 * A raw, possibly multi-statement SurrealQL query wrapped as a builder, so it
 * shares the {@see QueryBuilder::compile()} / execute() / json() surface with
 * the statement builders.
 *
 * @template TResult
 * @extends QueryBuilder<TResult>
 */
final class Query extends QueryBuilder
{
    public function __construct(
        ?QueryExecutor $executor,
        public private(set) BoundQuery $statements,
    ) {
        parent::__construct($executor);
    }

    protected function build(): BoundQuery
    {
        return $this->statements;
    }

    /**
     * Execute and return every statement's result (not just the first).
     *
     * @return list<TResult>
     */
    public function collect(): array
    {
        return $this->dispatch();
    }
}
