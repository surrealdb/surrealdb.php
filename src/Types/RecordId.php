<?php

namespace SurrealDB\SDK\Types;

/**
 * A fully-qualified SurrealDB record identifier (e.g. `person:tobie`).
 *
 * Record IDs are SurrealQL identifiers, so they are escaped inline into the
 * query text rather than bound as parameters. The escaped form is exposed as a
 * computed (virtual) property via a property hook.
 *
 * The id part may be a string, integer, {@see Uuid}, array (array id), or object
 * (object id), matching SurrealDB's supported record id value kinds.
 *
 * @template TTable of string
 */
final class RecordId extends Value
{
    /** @var TTable */
    public string $table;

    /** @var string|int|array<mixed>|object */
    public string|int|array|object $id;

    /**
     * @param TTable $table
     * @param string|int|array<mixed>|object $id
     */
    public function __construct(string $table, string|int|array|object $id)
    {
        $this->table = $table;
        $this->id = $id;
    }

    /**
     * Named constructor: build a record id from its table and id parts.
     *
     * The ergonomic counterpart to `new RecordId($table, $id)`, handy for
     * fluent call sites such as `RecordId::from('person', 'tobie')`.
     *
     * @template TNewTable of string
     *
     * @param TNewTable $table
     * @param string|int|array<mixed>|object $id
     *
     * @return self<TNewTable>
     */
    public static function from(string $table, string|int|array|object $id): self
    {
        return new self($table, $id);
    }

    /**
     * The escaped `table:id` form.
     */
    public string $escaped {
        get => Table::escapeIdent($this->table) . ':' . self::escapeId($this->id);
    }

    public function equals(mixed $other): bool
    {
        if (!$other instanceof self || $this->table !== $other->table) {
            return false;
        }

        if ($this->id instanceof Value) {
            return $this->id->equals($other->id);
        }

        return $this->id == $other->id;
    }

    public function __toString(): string
    {
        return $this->escaped;
    }

    public function escape(): string
    {
        return $this->escaped;
    }

    /**
     * @return array{'$recordId': array{tb: TTable, id: string|int|array<mixed>|object}}
     */
    public function jsonSerialize(): array
    {
        return ['$recordId' => ['tb' => $this->table, 'id' => $this->id]];
    }

    /**
     * @param string|int|array<mixed>|object $id
     */
    private static function escapeId(string|int|array|object $id): string
    {
        if (is_int($id)) {
            return (string) $id;
        }

        if ($id instanceof Uuid) {
            return $id->escape();
        }

        if ($id instanceof Value) {
            return $id->escape();
        }

        if (is_array($id) || is_object($id)) {
            return Value::toSurql($id);
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $id) === 1) {
            return $id;
        }

        return '`' . str_replace('`', '\\`', $id) . '`';
    }
}
