<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Types\GeometryMultiPoint;

/**
 * A multi-point: tag 91 with its points (tag 88).
 */
final class GeometryMultiPointTag implements CborTag
{
	public function tag(): int
	{
		return Tag::GEOMETRY_MULTIPOINT->value;
	}

	public function class(): string
	{
		return GeometryMultiPoint::class;
	}

	/**
	 * The parts are written by their own handlers.
	 *
	 * @param GeometryMultiPoint $value
	 * @return list<mixed>
	 */
	public function encode(object $value, CborContext $context): array
	{
		return $value->points;
	}

	public function decode(mixed $content, CborContext $context): GeometryMultiPoint
	{
		return new GeometryMultiPoint(...array_map(GeometryPointTag::point(...), TagContent::items($content)));
	}
}
