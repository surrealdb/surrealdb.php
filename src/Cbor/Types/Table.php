<?php

namespace Surreal\Cbor\Types;

final class Table
{
    private string $table;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * Creates a new table from a table name.
     * @param string $table
     * @return Table
     */
    public static function create(string $table): Table
    {
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

    /**
     * Checks if this table is equal to another table
     * @param Table $table
     * @return bool
     */
    public function equals(Table $table): bool
    {
        return $this->table === $table->table;
    }
}