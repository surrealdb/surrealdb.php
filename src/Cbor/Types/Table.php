<?php

namespace Surreal\Cbor\Types;

use InvalidArgumentException;

final class Table
{
    private string $table;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public static function fromString(string $table): Table
    {
        if(str_contains($table, ':')) {
            throw new InvalidArgumentException('Table name cannot contain ":" character');
        }

        return new Table($table);
    }

    public function toString(): string
    {
        return $this->table;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function __toString(): string
    {
        return $this->table;
    }
}