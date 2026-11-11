<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Types\GeometryMultiLine;

/**
 * A multi-line: tag 92 with its lines (tag 89).
 */
final class GeometryMultiLineTag implements CborTag
{
	public function tag(): int
	{
		return Tag::GEOMETRY_MULTILINE->value;
	}

	public function class(): string
	{
		return GeometryMultiLine::class;
	}

	/**
	 * The parts are written by their own handlers.
	 *
	 * @param GeometryMultiLine $value
	 * @return list<mixed>
	 */
	public function encode(object $value, CborContext $context): array
	{
		return $value->lines;
	}

	public function decode(mixed $content, CborContext $context): GeometryMultiLine
	{
		return new GeometryMultiLine(...array_map(GeometryLineTag::line(...), TagContent::items($content)));
	}
}
