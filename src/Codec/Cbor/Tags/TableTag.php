<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Types\Table;

/**
 * A table name: tag 7 with the name as text.
 */
final class TableTag implements CborTag
{
	public function tag(): int
	{
		return Tag::TABLE->value;
	}

	public function class(): string
	{
		return Table::class;
	}

	/**
	 * @param Table<string> $value
	 */
	public function encode(object $value, CborContext $context): string
	{
		return $value->name;
	}

	/**
	 * @return Table<string>
	 */
	public function decode(mixed $content, CborContext $context): Table
	{
		return new Table(TagContent::text($content));
	}
}
