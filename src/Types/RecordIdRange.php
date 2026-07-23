<?php

namespace SurrealDB\Types;

/**
 * A SurrealQL record id range (e.g. `person:1..=100`): a table paired with a
 * begin/end bound, used to select a contiguous slice of records by id.
 *
 * A `null` bound denotes an open end.
 *
 * @template TTable of string
 */
final class RecordIdRange extends Value
{
    /**
     * @param TTable $table
     * @param BoundIncluded<mixed>|BoundExcluded<mixed>|null $begin
     * @param BoundIncluded<mixed>|BoundExcluded<mixed>|null $end
     */
    public function __construct(
        public readonly string $table,
        public readonly BoundIncluded|BoundExcluded|null $begin,
        public readonly BoundIncluded|BoundExcluded|null $end,
    ) {}

    public function equals(mixed $other): bool
    {
        return $other instanceof self
            && $this->table === $other->table
            && new Range($this->begin, $this->end)->equals(new Range($other->begin, $other->end));
    }

    public function __toString(): string
    {
        return $this->escape();
    }

    /**
     * Inline SurrealQL literal form, e.g. `person:1..=100`.
     */
    public function escape(): string
    {
        return Table::escapeIdent($this->table) . ':'
            . Range::escapeBound($this->begin)
            . Range::joinBounds($this->begin, $this->end)
            . Range::escapeBound($this->end);
    }

    /**
     * @return array{'$recordId': array{tb: TTable, id: array{'$range': array{begin: BoundIncluded<mixed>|BoundExcluded<mixed>|null, end: BoundIncluded<mixed>|BoundExcluded<mixed>|null}}}}
     */
    public function jsonSerialize(): array
    {
        return ['$recordId' => [
            'tb' => $this->table,
            'id' => ['$range' => ['begin' => $this->begin, 'end' => $this->end]],
        ]];
    }
}
