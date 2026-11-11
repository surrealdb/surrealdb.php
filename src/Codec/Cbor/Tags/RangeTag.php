<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Types\BoundExcluded;
use SurrealDB\Types\BoundIncluded;
use SurrealDB\Types\Range;

/**
 * A range: tag 49 with [begin, end], each a bound (tag 50 or 51) or null for
 * an open end.
 */
final class RangeTag implements ContainerTag
{
	public function tag(): int
	{
		return Tag::RANGE->value;
	}

	public function class(): string
	{
		return Range::class;
	}

	/**
	 * The bounds are written by their own handlers.
	 *
	 * @param Range<mixed, mixed> $value
	 * @return array{BoundIncluded<mixed>|BoundExcluded<mixed>|null, BoundIncluded<mixed>|BoundExcluded<mixed>|null}
	 */
	public function encode(object $value, CborContext $context): array
	{
		return [$value->begin, $value->end];
	}

	/**
	 * @return Range<mixed, mixed>
	 */
	public function decode(mixed $content, ?CborContext $context = null): Range
	{
		$parts = TagContent::items($content);

		return new Range(self::bound($parts[0] ?? null), self::bound($parts[1] ?? null));
	}

	/**
	 * @return BoundIncluded<mixed>|BoundExcluded<mixed>|null
	 */
	private static function bound(mixed $value): BoundIncluded|BoundExcluded|null
	{
		return $value instanceof BoundIncluded || $value instanceof BoundExcluded ? $value : null;
	}
}
