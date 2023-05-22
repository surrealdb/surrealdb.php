<?php

namespace Surreal;

class Thing
{

	protected string $raw;

	protected ?string $table = null;
	protected ?string $id    = null;

	public function __construct(string $thing)
	{
		$this->raw = $thing;
		$this->parse();
	}

	public static function make(string $table, mixed $id): Thing
	{
		return new Thing($table . ':' . $id);
	}

	private function parse(): void
	{
		// A "thing" consists of "{table_name}:{id_string}"
		// It can also just be "{table_name"}
		// e.g. "user:1234"
		// e.g. "user:`1234`"

		// Split the thing into the table and id
		// e.g. ["user", "1234"]

		$parts = explode(':', $this->raw);

		$table = $parts[0];

		if (count($parts) < 2) {
			$this->table = $table;

			return;
		}

		$id = $parts[1];

		// If the id is surrounded by backticks, remove them
		if (str_starts_with($id, '`')) {
			$id = substr($id, 1);
		}
		if (str_ends_with($id, '`')) {
			$id = substr($id, 0, -1);
		}

		$this->table = $table;
		$this->id    = $id;
	}

	/**
	 * @return string
	 */
	public function getRaw(): string
	{
		return $this->raw;
	}

	/**
	 * @return string
	 */
	public function getTable(): string
	{
		return $this->table;
	}

	public function hasId(): bool
	{
		return $this->id !== null;
	}

	/**
	 * @return string
	 */
	public function getId(): string
	{
		return $this->id;
	}

	public function __toString(): string
	{
		return $this->table . ($this->id !== null ? ':' . $this->id : '');
	}


}
