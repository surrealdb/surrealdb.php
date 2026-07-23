<?php

namespace SurrealDB\Query;

use SurrealDB\Contracts\QueryExecutor;
use SurrealDB\Exceptions\ExpressionException;

/**
 * Fluent builder for invoking a SurrealQL / SurrealML function, e.g.
 * `fn::greet('Tobie')` or `ml::recommend<1.0.0>(...)`. Arguments are bound as
 * parameters; the function name and version are validated and emitted inline.
 *
 * @template TResult
 * @extends QueryBuilder<TResult>
 */
final class RunQuery extends QueryBuilder
{
    private const string NAME_PATTERN = '/^[a-zA-Z0-9_:]+$/';
    private const string VERSION_PATTERN = '/^[0-9.]+$/';

    /**
     * @param list<mixed> $args
     */
    public function __construct(
        ?QueryExecutor $executor,
        public private(set) string $name,
        public private(set) ?string $version = null,
        public private(set) array $args = [],
    ) {
        parent::__construct($executor);
    }

    protected function build(): BoundQuery
    {
        if (preg_match(self::NAME_PATTERN, $this->name) !== 1) {
            throw new ExpressionException("Invalid function name: {$this->name}");
        }

        $query = new BoundQuery($this->name);

        if ($this->version !== null) {
            if (preg_match(self::VERSION_PATTERN, $this->version) !== 1) {
                throw new ExpressionException("Invalid function version: {$this->version}");
            }

            $query->append('<' . $this->version . '>');
        }

        $query->append('(');

        foreach ($this->args as $arg) {
            $query->append($query->bind($arg) . ', ');
        }

        $query->append(')');

        return $query;
    }
}
