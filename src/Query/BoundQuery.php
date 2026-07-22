<?php

namespace SurrealDB\SDK\Query;

use SurrealDB\SDK\Exceptions\ExpressionException;
use function array_key_exists;

/**
 * A parameter-bound SurrealQL fragment: the query text plus the values bound to
 * generated placeholders. State is publicly readable but only internally
 * writable via asymmetric visibility, replacing getter boilerplate.
 *
 * This is the minimal contract relied upon by the networking layer; the full
 * fluent builder layer (companion plan) produces these.
 */
final class BoundQuery
{
    private int $counter = 0;

    /**
     * @param array<string,mixed> $bindings
     */
    public function __construct(
        public private(set) string $query = '',
        public private(set) array $bindings = [],
    ) {}

    /**
     * Bind a value, returning its generated `$placeholder`.
     */
    public function bind(mixed $value): string
    {
        $name = 'bind_' . $this->counter++;
        $this->bindings[$name] = $value;

        return '$' . $name;
    }

    /**
     * Append raw SurrealQL (or another {@see BoundQuery}), merging bindings and
     * guarding against duplicate keys.
     *
     * @param array<string,mixed> $bindings
     */
    public function append(self|string $sql, array $bindings = []): self
    {
        if ($sql instanceof self) {
            $bindings = $sql->bindings;
            $sql = $sql->query;
        }

        foreach (array_keys($bindings) as $key) {
            if (array_key_exists($key, $this->bindings)) {
                throw new ExpressionException("Duplicate binding key: {$key}");
            }
        }

        $this->query .= $sql;
        $this->bindings = [...$this->bindings, ...$bindings];

        return $this;
    }
}
