<?php

namespace Surreal\Cbor\Types;

final readonly class Table
{
    public string $table;

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

    public function getTable(): string
    {
        return $this->table;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function toString(): string
    {
        return $this->table;
    }

    /**
     * Checks if this table is equal to another table
     * @param Table|string $table
     * @return bool
     */
    public function equals(Table|string $table): bool
    {
        if ($table instanceof Table) {
            return $this->table === $table->table;
        }

        return $this->table === $table;
    }
}