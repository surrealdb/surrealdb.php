<?php

namespace Surreal\Cbor\Types;

use InvalidArgumentException;

final class RecordId
{
	private string $table;
	private string $id;

	public function __construct(string $table, string $id)
	{
		$this->table = $table;
		$this->id = $id;
	}

	/**
	 * Parses a record id from a string in the format "table:id"
	 * @param string $recordId
	 * @return RecordId
	 */
	public static function fromString(string $recordId): RecordId
	{
		$parts = explode(":", $recordId);

		if (count($parts) !== 2) {
			throw new InvalidArgumentException("Invalid record id: " . $recordId);
		}

		return new RecordId($parts[0], $parts[1]);
	}

    /**
     * Parses a record id from an array in the format [table, id]
     * @param array $record
     * @return RecordId
     */
    public static function fromArray(array $record): RecordId
    {
        if(count($record) !== 2) {
            throw new InvalidArgumentException("Invalid record id");
        }

        return new RecordId($record[0], $record[1]);
    }

    /**
     * @return string - table:id
     */
	public function __toString(): string
    {
		return $this->table . ":" . $this->id;
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
     * Converts this record id to an associative array
     * @return array{table:string,id:string}
     */
	public function toAssoc(): array
	{
		return [
			"table" => $this->table,
			"id" => $this->id
		];
	}

    /**
     * Converts this record id to an array
     * @return array - [table, id]
     */
	public function toArray(): array
	{
		return [$this->table, $this->id];
	}

    /**
     * Checks if this record id is equal to another record id
     * @param RecordId|string $recordId
     * @return boolean
     */
	public function equals(RecordId|string $recordId): bool
	{
		if (is_string($recordId)) {
			$recordId = RecordId::fromString($recordId);
		}

		return $this->table === $recordId->table && $this->id === $recordId->id;
	}
}
