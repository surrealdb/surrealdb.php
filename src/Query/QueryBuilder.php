<?php

namespace SurrealDB\Query;

use SurrealDB\Contracts\QueryExecutor;
use SurrealDB\Contracts\StreamingQueryExecutor;
use SurrealDB\Contracts\SurrealType;
use SurrealDB\Exceptions\ExpressionException;
use SurrealDB\Exceptions\SurrealException;
use SurrealDB\Query\Frame\DoneFrame;
use SurrealDB\Query\Frame\ErrorFrame;
use SurrealDB\Query\Frame\ValueFrame;
use SurrealDB\Support\Jsonify;
use SurrealDB\Types\Range;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\RecordIdRange;
use SurrealDB\Types\StringRecordId;
use SurrealDB\Types\Table;
use function is_array;
use function is_string;
use function strlen;

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
        return $this->result($this->dispatch()[0] ?? null);
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

            $result = $this->result($this->output($chunk->result));

            if (is_array($result) && array_is_list($result)) {
                foreach ($result as $value) {
                    yield $this->valueFrame($chunk->index, $value, false);
                }
            } else {
                yield $this->valueFrame($chunk->index, $result, true);
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
                : new QueryResponse(true, $this->result($this->output($chunk->result)), time: $chunk->time, type: $chunk->type);
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
    /**
     * Turn a statement's result into what this builder returns. A builder
     * whose {@see self::execute()} reshapes or checks the result overrides
     * this, so {@see self::stream()} and {@see self::responses()} do the same.
     *
     * @return TResult
     */
    protected function result(mixed $result): mixed
    {
        /** @var TResult */
        return $result;
    }

    /**
     * @return ValueFrame<TResult>
     */
    private function valueFrame(int $query, mixed $value, bool $single): ValueFrame
    {
        /** @var TResult $value */
        return new ValueFrame($query, $value, $single);
    }

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
     * Append a statement target, prefixing `ONLY` for one record so the
     * result is that record rather than a list (mirroring SurrealDB). Pass
     * `$only: false` to leave it out even then.
     *
     * @param RecordId<string>|RecordIdRange<string>|StringRecordId|Table<string>|string $what
     */
    protected function appendTarget(
        BoundQuery $query,
        RecordId|RecordIdRange|StringRecordId|Table|string $what,
        bool $only = true,
    ): void {
        if ($only && self::isOneRecord($what)) {
            $query->append('ONLY ');
        }

        $query->append($this->thing($what));
    }

    /**
     * Whether `$what` names one record: a {@see StringRecordId}, or a
     * {@see RecordId} that is not a range.
     */
    protected static function isOneRecord(mixed $what): bool
    {
        return $what instanceof StringRecordId || ($what instanceof RecordId && !self::isRange($what));
    }

    /**
     * Whether `$what` names a range of records: a {@see RecordIdRange}, or a
     * {@see RecordId} whose id is a {@see Range}. SurrealDB refuses `ONLY` on
     * a range ("Expected a single result output when using the ONLY
     * keyword"). A range in a {@see StringRecordId} is refused by
     * {@see self::thing()}.
     */
    protected static function isRange(mixed $what): bool
    {
        return $what instanceof RecordIdRange || ($what instanceof RecordId && $what->id instanceof Range);
    }

    /**
     * Whether record id text has a `..` outside quotes, backticks, `⟨⟩` and
     * brackets: `person:1..3` and `person:[1]..[3]` do, while
     * `person:⟨a..b⟩`, `person:[1..2]` and `person:{a: 1..2}` are one record.
     */
    private static function hasTopLevelDotDot(string $id): bool
    {
        $closer = null;
        $depth = 0;
        $length = strlen($id);

        for ($i = 0; $i < $length; $i++) {
            $char = $id[$i];

            if ($closer !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif (substr($id, $i, strlen($closer)) === $closer) {
                    $i += strlen($closer) - 1;
                    $closer = null;
                }
            } elseif (str_contains('"\'`', $char)) {
                $closer = $char;
            } elseif (substr($id, $i, strlen('⟨')) === '⟨') {
                $closer = '⟩';
                $i += strlen('⟨') - 1;
            } elseif (str_contains('[{(', $char)) {
                $depth++;
            } elseif (str_contains(']})', $char)) {
                $depth = max($depth - 1, 0);
            } elseif ($depth === 0 && $char === '.' && ($id[$i + 1] ?? '') === '.') {
                return true;
            }
        }

        return false;
    }

    /**
     * Escape a "thing" (record id, table, table name, or list thereof) inline.
     * Identifiers cannot be passed as parameters over the JSON protocol, so
     * they are escaped via {@see SurrealType::escape()}.
     *
     * A plain string is always a table name and is escaped as an identifier;
     * it is never interpolated as raw SurrealQL. Use a {@see RecordId} or a
     * {@see StringRecordId} to target a record.
     *
     * A {@see StringRecordId} with a `..` at its top level is refused: the
     * servers read a record range written as text in different ways
     * (SurrealDB 2.6 refuses `r"person:1..3"`, 3.3 and 4.0 read only
     * `person:1`).
     */
    protected function thing(mixed $value): string
    {
        if (is_array($value)) {
            return '[' . implode(', ', array_map($this->thing(...), $value)) . ']';
        }

        if ($value instanceof StringRecordId && self::hasTopLevelDotDot($value->id)) {
            throw new ExpressionException(
                "StringRecordId '{$value->id}' is a record range written as text, which SurrealDB servers read "
                . 'in different ways. Use a RecordIdRange, or a RecordId whose id is a Range, instead.',
            );
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
