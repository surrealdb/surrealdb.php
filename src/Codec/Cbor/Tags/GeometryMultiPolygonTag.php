<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Types\GeometryMultiPolygon;

/**
 * A multi-polygon: tag 93 with its polygons (tag 90).
 */
final class GeometryMultiPolygonTag implements CborTag
{
	public function tag(): int
	{
		return Tag::GEOMETRY_MULTIPOLYGON->value;
	}

	public function class(): string
	{
		return GeometryMultiPolygon::class;
	}

	/**
	 * The parts are written by their own handlers.
	 *
	 * @param GeometryMultiPolygon $value
	 * @return list<mixed>
	 */
	public function encode(object $value, CborContext $context): array
	{
		return $value->polygons;
	}

	public function decode(mixed $content, CborContext $context): GeometryMultiPolygon
	{
		return new GeometryMultiPolygon(...array_map(GeometryPolygonTag::polygon(...), TagContent::items($content)));
	}
}
