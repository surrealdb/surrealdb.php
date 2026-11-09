<?php

namespace SurrealDB\SDK\Types;

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
     * Words SurrealQL reads as keywords or values rather than as names, so a
     * table, field or record id by one of these names is quoted. As in the JS
     * SDK: `RESERVED_KEYWORD` and `EXPLAIN` from SurrealDB's
     * `syn/lexer/keywords.rs`, and the number literals `NaN` and `Infinity`.
     * Matched without regard to case; quoting more than needed is harmless.
     */
    private const array RESERVED = [
        'alter' => true, 'begin' => true, 'break' => true, 'cancel' => true, 'commit' => true,
        'continue' => true, 'create' => true, 'define' => true, 'delete' => true, 'for' => true,
        'if' => true, 'info' => true, 'insert' => true, 'kill' => true, 'live' => true,
        'option' => true, 'rebuild' => true, 'return' => true, 'relate' => true, 'remove' => true,
        'select' => true, 'let' => true, 'show' => true, 'sleep' => true, 'throw' => true,
        'update' => true, 'upsert' => true, 'use' => true, 'diff' => true, 'rand' => true,
        'none' => true, 'null' => true, 'after' => true, 'before' => true, 'value' => true,
        'by' => true, 'all' => true, 'true' => true, 'false' => true, 'where' => true,
        'table' => true, 'tb' => true, 'sequence' => true, 'function' => true, 'explain' => true,
        'nan' => true, 'infinity' => true,
    ];

    /**
     * Emit a bare identifier when safe, otherwise a backtick-quoted one with
     * backslashes and backticks escaped, so no input can close the quotes.
     */
    public static function escapeIdent(string $ident): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident) === 1 && !isset(self::RESERVED[strtolower($ident)])) {
            return $ident;
        }

        return '`' . str_replace(['\\', '`'], ['\\\\', '\\`'], $ident) . '`';
    }
}
