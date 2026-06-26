<?php

namespace SurrealDB\SDK\Enum;

/**
 * The data mutation keyword applied by CREATE / UPDATE / UPSERT statements.
 */
enum Mutation: string
{
    case CONTENT = 'content';
    case MERGE = 'merge';
    case REPLACE = 'replace';
    case PATCH = 'patch';

    /**
     * The uppercased SurrealQL keyword (`CONTENT`, `MERGE`, ...).
     */
    public function toSql(): string
    {
        return strtoupper($this->value);
    }
}
