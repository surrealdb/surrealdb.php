<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Exceptions\SerializationException;
use SurrealDB\Types\Geometry;
use SurrealDB\Types\GeometryCollection;

/**
 * A geometry collection: tag 94 with its geometries, each tagged.
 */
final class GeometryCollectionTag implements CborTag
{
	public function tag(): int
	{
		return Tag::GEOMETRY_COLLECTION->value;
	}

	public function class(): string
	{
		return GeometryCollection::class;
	}

	/**
	 * The parts are written by their own handlers.
	 *
	 * @param GeometryCollection $value
	 * @return list<mixed>
	 */
	public function encode(object $value, CborContext $context): array
	{
		return $value->geometries;
	}

	public function decode(mixed $content, CborContext $context): GeometryCollection
	{
		return new GeometryCollection(...array_map(self::geometry(...), TagContent::items($content)));
	}

	private static function geometry(mixed $value): Geometry
	{
		if (!$value instanceof Geometry) {
			throw new SerializationException(
				"Serialization",
				"Expected a tagged geometry value in CBOR geometry collection.",
			);
		}

		return $value;
	}
}
