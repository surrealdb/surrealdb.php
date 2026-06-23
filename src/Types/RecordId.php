<?php

namespace SurrealDB\SDK\Types;

use SurrealDB\SDK\Exceptions\InvalidValueException;

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
     * Parse a `table:id` string into a {@see RecordId}.
     *
     * Splits on the first `:`, unwraps backtick- or angle-bracket-escaped
     * identifiers, and coerces a bare integer id to `int` to match SurrealDB's
     * record-id semantics. This is the inverse of {@see self::$escaped} and is
     * used to normalise the plain string ids returned over the JSON wire
     * protocol back into typed record ids.
     *
     * @return self<string>
     */
    public static function parse(string $thing): self
    {
        $position = strpos($thing, ':');

        if ($position === false || $position === 0) {
            throw new InvalidValueException(
                sprintf('"%s" is not a valid record id; expected "table:id".', $thing),
            );
        }

        return new self(
            self::unwrapIdent(substr($thing, 0, $position)),
            self::parseId(substr($thing, $position + 1)),
        );
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

    /**
     * Strip backtick or angle-bracket (`⟨ ⟩`) escaping from an identifier.
     */
    private static function unwrapIdent(string $ident): string
    {
        if (strlen($ident) >= 2 && str_starts_with($ident, '`') && str_ends_with($ident, '`')) {
            return str_replace('\\`', '`', substr($ident, 1, -1));
        }

        if (str_starts_with($ident, '⟨') && str_ends_with($ident, '⟩')) {
            return substr($ident, strlen('⟨'), -strlen('⟩'));
        }

        return $ident;
    }

    /**
     * Parse the id portion of a record id string into its typed PHP value.
     */
    private static function parseId(string $id): string|int
    {
        if (str_starts_with($id, '`') || str_starts_with($id, '⟨')) {
            return self::unwrapIdent($id);
        }

        if (preg_match('/^\d+$/', $id) === 1) {
            return (int) $id;
        }

        return $id;
    }
}
