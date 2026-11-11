<?php

namespace SurrealDB\Query;

use SurrealDB\Contracts\QueryExecutor;
use SurrealDB\Exceptions\ExpressionException;

/**
 * Fluent builder for invoking a SurrealQL / SurrealML function, e.g.
 * `fn::greet('Tobie')` or `ml::recommend<1.0.0>(...)`. Arguments are bound as
 * parameters; the function name and version are validated and emitted inline.
 *
 * A name is two or more segments joined by `::` (`fn::greet`, `string::len`,
 * `ml::model`), or one of the built-ins without a namespace: `count`, `not`,
 * `rand` and `sleep`. Any other word would be read as whatever it names, so
 * `DELETE` would send `DELETE($arg)`, a statement that deletes `$arg`. A
 * version is `major.minor.patch`. Anything else is refused before it is sent.
 *
 * @template TResult
 * @extends QueryBuilder<TResult>
 */
final class RunQuery extends QueryBuilder
{
    private const string NAME_PATTERN = '/^(?:[a-zA-Z0-9_]+(?:::[a-zA-Z0-9_]+)+|count|not|rand|sleep)$/D';
    private const string VERSION_PATTERN = '/^\d+\.\d+\.\d+$/D';

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
            throw new ExpressionException(
                "Invalid function name: {$this->name}. A name has segments joined by '::', such as fn::greet "
                . 'or string::len; count, not, rand and sleep are the only names without one.',
            );
        }

        $query = new BoundQuery($this->name);

        if ($this->version !== null) {
            if (preg_match(self::VERSION_PATTERN, $this->version) !== 1) {
                throw new ExpressionException(
                    "Invalid function version: {$this->version}. A version is major.minor.patch, such as 1.0.0.",
                );
            }

            $query->append('<' . $this->version . '>');
        }

        $query->append('(' . implode(', ', array_map($query->bind(...), $this->args)) . ')');

        return $query;
    }
}
