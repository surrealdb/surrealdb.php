<?php

namespace SurrealDB\SDK\Enum;

/**
 * The `RETURN` clause variants accepted by mutating statements
 * (CREATE / UPDATE / UPSERT / DELETE / INSERT / RELATE).
 */
enum Output: string
{
    case NONE = 'none';
    case NULL_ = 'null';
    case DIFF = 'diff';
    case BEFORE = 'before';
    case AFTER = 'after';

    /**
     * The literal emitted into the `RETURN` clause.
     */
    public function toSql(): string
    {
        return match ($this) {
            self::NULL_ => 'null',
            self::NONE => 'NONE',
            self::DIFF => 'DIFF',
            self::BEFORE => 'BEFORE',
            self::AFTER => 'AFTER',
        };
    }
}
