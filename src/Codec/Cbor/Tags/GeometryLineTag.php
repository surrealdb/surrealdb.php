<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Types\GeometryLine;

/**
 * A line: tag 89 with its points (tag 88).
 */
final class GeometryLineTag implements CborTag
{
	public function tag(): int
	{
		return Tag::GEOMETRY_LINE->value;
	}

	public function class(): string
	{
		return GeometryLine::class;
	}

	/**
	 * The parts are written by their own handlers.
	 *
	 * @param GeometryLine $value
	 * @return list<mixed>
	 */
	public function encode(object $value, CborContext $context): array
	{
		return $value->points;
	}

	public function decode(mixed $content, CborContext $context): GeometryLine
	{
		return self::line($content);
	}

	/**
	 * A line from a decoded tag 89, or from its untagged list of points.
	 */
	public static function line(mixed $value): GeometryLine
	{
		return $value instanceof GeometryLine
			? $value
			: new GeometryLine(...array_map(GeometryPointTag::point(...), TagContent::items($value)));
	}
}
