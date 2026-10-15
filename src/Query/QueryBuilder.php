<?php

namespace SurrealDB\SDK\Query;

use SurrealDB\SDK\Contracts\QueryExecutor;
use SurrealDB\SDK\Contracts\StreamingQueryExecutor;
use SurrealDB\SDK\Contracts\SurrealType;
use SurrealDB\SDK\Exceptions\ExpressionException;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Query\Frame\DoneFrame;
use SurrealDB\SDK\Query\Frame\ErrorFrame;
use SurrealDB\SDK\Query\Frame\ValueFrame;
use SurrealDB\SDK\Support\Jsonify;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;
use function is_array;
use function is_string;

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
     * Return plain JSON-compatible values instead of SurrealDB types: record
     * ids, datetimes, durations and similar become strings, geometries become
     * GeoJSON and NONE becomes null (see {@see Jsonify}).
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
     * Execute and yield the results as frames: for each statement, its
     * values (one frame per element when it returns a list), then a
     * {@see DoneFrame}, or an {@see ErrorFrame} if it failed. A failing
     * statement does not stop the stream. Mirrors the JS SDK's `stream()`.
     *
     * An empty object decodes to an empty PHP array, which cannot be told
     * apart from an empty list, so a statement returning `{}` yields only its
     * {@see DoneFrame}, like one returning `[]`. Use {@see self::responses()}
     * when that difference matters.
     *
     * @return \Generator<int, ValueFrame<TResult>|ErrorFrame<TResult>|DoneFrame<TResult>>
     */
    public function stream(): \Generator
    {
        foreach ($this->streamingExecutor()->stream($this->build()) as $chunk) {
            if ($chunk->error !== null) {
                yield new ErrorFrame($chunk->index, $chunk->error, $chunk->time);

                continue;
            }

            $result = $chunk->result;

            if (is_array($result) && array_is_list($result)) {
                foreach ($result as $value) {
                    yield new ValueFrame($chunk->index, $this->output($value), false);
                }
            } else {
                yield new ValueFrame($chunk->index, $this->output($result), true);
            }

            yield new DoneFrame($chunk->index, $chunk->time, $chunk->type);
        }
    }

    /**
     * Execute and return every statement's outcome without throwing for
     * failed statements. Mirrors the JS SDK's `responses()`.
     *
     * @return list<QueryResponse<TResult>>
     */
    public function responses(): array
    {
        $responses = [];

        foreach ($this->streamingExecutor()->stream($this->build()) as $chunk) {
            $responses[] = $chunk->error !== null
                ? new QueryResponse(false, error: $chunk->error, time: $chunk->time)
                : new QueryResponse(true, $this->output($chunk->result), time: $chunk->time, type: $chunk->type);
        }

        return $responses;
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

        $results = $this->executor->query($this->build());

        if ($this->json) {
            $results = array_map(Jsonify::value(...), $results);
        }

        /** @var list<TResult> $results */
        return $results;
    }

    /**
     * @return TResult
     */
    private function output(mixed $value): mixed
    {
        /** @var TResult */
        return $this->json ? Jsonify::value($value) : $value;
    }

    private function streamingExecutor(): StreamingQueryExecutor
    {
        if (!$this->executor instanceof StreamingQueryExecutor) {
            throw new SurrealException(
                static::class . ' needs a StreamingQueryExecutor to stream; build it via Surreal or pass one explicitly.',
            );
        }

        return $this->executor;
    }

    /**
     * Append a statement target, prefixing `ONLY` for single record IDs so the
     * result is a single record rather than a list (mirroring SurrealDB).
     *
     * @param RecordId<string>|RecordIdRange<string>|StringRecordId|Table<string>|string $what
     */
    protected function appendTarget(BoundQuery $query, RecordId|RecordIdRange|StringRecordId|Table|string $what): void
    {
        if ($what instanceof RecordId || $what instanceof StringRecordId) {
            $query->append('ONLY ');
        }

        $query->append($this->thing($what));
    }

    /**
     * Escape a "thing" (record id, table, table name, or list thereof) inline.
     * Identifiers cannot be passed as parameters over the JSON protocol, so
     * they are escaped via {@see SurrealType::escape()}.
     *
     * A plain string is always a table name and is escaped as an identifier;
     * it is never interpolated as raw SurrealQL. Use a {@see RecordId} or a
     * {@see StringRecordId} to target a record.
     */
    protected function thing(mixed $value): string
    {
        if (is_array($value)) {
            return '[' . implode(', ', array_map($this->thing(...), $value)) . ']';
        }

        if ($value instanceof SurrealType) {
            return $value->escape();
        }

        if (is_string($value)) {
            return Table::escapeIdent($value);
        }

        throw new ExpressionException('Unsupported statement target: ' . get_debug_type($value));
    }
}
