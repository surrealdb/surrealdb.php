<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Types\GeometryPolygon;

/**
 * A polygon: tag 90 with its rings, each a line (tag 89).
 */
final class GeometryPolygonTag implements CborTag
{
	public function tag(): int
	{
		return Tag::GEOMETRY_POLYGON->value;
	}

	public function class(): string
	{
		return GeometryPolygon::class;
	}

	/**
	 * The parts are written by their own handlers.
	 *
	 * @param GeometryPolygon $value
	 * @return list<mixed>
	 */
	public function encode(object $value, CborContext $context): array
	{
		return $value->rings;
	}

	public function decode(mixed $content, CborContext $context): GeometryPolygon
	{
		return self::polygon($content);
	}

	/**
	 * A polygon from a decoded tag 90, or from its untagged list of rings.
	 */
	public static function polygon(mixed $value): GeometryPolygon
	{
		return $value instanceof GeometryPolygon
			? $value
			: new GeometryPolygon(...array_map(GeometryLineTag::line(...), TagContent::items($value)));
	}
}
