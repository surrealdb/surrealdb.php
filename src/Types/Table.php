<?php

namespace SurrealDB\Types;

/**
 * A SurrealDB table reference (e.g. `person`).
 *
 * Table names are SurrealQL identifiers, so they are escaped inline into the
 * query text rather than bound as parameters.
 *
 * @template TTable of string
 */
final class Table extends Value
{
    /** @var TTable */
    public readonly string $name;

    /**
     * @param TTable $name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->escape();
    }

    public function escape(): string
    {
        return self::escapeIdent($this->name);
    }

    /**
     * @return array{'$table': TTable}
     */
    public function jsonSerialize(): array
    {
        return ['$table' => $this->name];
    }

    /**
     * Emit a bare identifier when safe, otherwise a backtick-quoted one.
     */
    public static function escapeIdent(string $ident): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident) === 1) {
            return $ident;
        }

        return '`' . str_replace('`', '\\`', $ident) . '`';
    }
}
