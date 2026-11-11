<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Types\GeometryPoint;

/**
 * A point: tag 88 with [longitude, latitude].
 */
final class GeometryPointTag implements CborTag
{
	public function tag(): int
	{
		return Tag::GEOMETRY_POINT->value;
	}

	public function class(): string
	{
		return GeometryPoint::class;
	}

	/**
	 * @param GeometryPoint $value
	 * @return array{float, float}
	 */
	public function encode(object $value, CborContext $context): array
	{
		return [$value->longitude, $value->latitude];
	}

	public function decode(mixed $content, CborContext $context): GeometryPoint
	{
		return self::point($content);
	}

	/**
	 * A point from a decoded tag 88, or from its untagged [longitude, latitude].
	 */
	public static function point(mixed $value): GeometryPoint
	{
		if ($value instanceof GeometryPoint) {
			return $value;
		}

		$parts = TagContent::items($value);

		return new GeometryPoint((float) ($parts[0] ?? 0.0), (float) ($parts[1] ?? 0.0));
	}
}
