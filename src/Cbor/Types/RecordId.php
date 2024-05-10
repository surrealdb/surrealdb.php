<?php

namespace Surreal\Cbor\Types;

use InvalidArgumentException;
use Surreal\Core\Utils\Helpers;

final class RecordId implements \JsonSerializable
{
    private string $table;
    private string|int|array $id;

    public function __construct(string $table, string|int|array $id)
    {
        $this->table = $table;
        $this->id = $id;
    }

    /**
     * Creates a new record id from a table name and an id
     * @param string $table
     * @param string $id
     * @return RecordId
     */
    public static function create(string $table, string $id): RecordId
    {
        return new RecordId($table, $id);
    }

    /**
     * Parses a record id from an array in the format [table, id]
     * @param array $record
     * @return RecordId
     */
    public static function fromArray(array $record): RecordId
    {
        if (count($record) !== 2) {
            throw new InvalidArgumentException("Invalid record id");
        }

        return new RecordId($record[0], $record[1]);
    }

    /**
     * @return string - table:id
     */
    public function __toString(): string
    {
        $tb = Helpers::escapeIdent($this->table);
        $id = is_string($this->id) ?
            Helpers::escapeIdent($this->id) :
            json_encode($this->id);

        return $tb . ":" . $id;
    }

    /**
     * Get the table name
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the record id
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Converts this record id to an array
     * @return array - [table, id]
     */
    public function toArray(): array
    {
        return [$this->table, $this->id];
    }

    public function jsonSerialize(): string
    {
        return $this->__toString();
    }
}
