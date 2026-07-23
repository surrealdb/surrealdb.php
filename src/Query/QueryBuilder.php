<?php

namespace SurrealDB\Query;

use SurrealDB\Contracts\QueryExecutor;
use SurrealDB\Contracts\SurrealType;
use SurrealDB\Exceptions\SurrealException;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\Table;
use function is_array;

/**
 * Base class for the fluent statement builders.
 *
 * Builders are mutable: every modifier mutates state and returns `$this`
 * (the idiomatic PHP builder style). Compile to a {@see BoundQuery} via
 * {@see self::compile()}, or run it through the bound {@see QueryExecutor}
 * via {@see self::execute()}.
 *
 * Builder state uses asymmetric visibility (`public private(set)`), so it is
 * publicly readable (handy for tests and the engine) yet only mutated through
 * the fluent API.
 *
 * @template TResult
 */
abstract class QueryBuilder
{
    public private(set) bool $json = false;

    public function __construct(
        public private(set) ?QueryExecutor $executor = null,
    ) {}

    /**
     * Compile the configured statement into its SurrealQL text + bindings.
     */
    abstract protected function build(): BoundQuery;

    /**
     * Request JSON-compatible results (loses SurrealDB type information).
     *
     * Reserved for codec-aware execution; carried through compilation untouched.
     */
    public function json(bool $json = true): static
    {
        $this->json = $json;

        return $this;
    }

    /**
     * Compile this builder into a {@see BoundQuery} without executing it.
     */
    public function compile(): BoundQuery
    {
        return $this->build();
    }

    /**
     * Execute the statement through the bound executor, returning the single
     * statement's result.
     *
     * @return TResult|null
     */
    public function execute(): mixed
    {
        return $this->dispatch()[0] ?? null;
    }

    /**
     * Run the compiled query through the executor, returning one entry per
     * statement.
     *
     * @return list<TResult>
     */
    protected function dispatch(): array
    {
        if ($this->executor === null) {
            throw new SurrealException(
                static::class . ' has no bound QueryExecutor; build it via Surreal or pass one explicitly.',
            );
        }

        /** @var list<TResult> $results */
        $results = $this->executor->query($this->build());

        return $results;
    }

    /**
     * Append a statement target, prefixing `ONLY` for single record IDs so the
     * result is a single record rather than a list (mirroring SurrealDB).
     *
     * @param RecordId<string>|Table<string>|string $what
     */
    protected function appendTarget(BoundQuery $query, RecordId|Table|string $what): void
    {
        if ($what instanceof RecordId) {
            $query->append('ONLY ');
        }

        $query->append($this->thing($what));
    }

    /**
     * Escape a "thing" (record id, table, raw identifier, or list thereof)
     * inline. Identifiers cannot be passed as parameters over the JSON protocol,
     * so they are escaped via {@see SurrealType::escape()}.
     */
    protected function thing(mixed $value): string
    {
        if (is_array($value)) {
            return '[' . implode(', ', array_map($this->thing(...), $value)) . ']';
        }

        if ($value instanceof SurrealType) {
            return $value->escape();
        }

        return (string) $value;
    }
}
