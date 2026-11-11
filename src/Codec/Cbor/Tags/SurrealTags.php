<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\TaggedValue;
use Beau\CborPHP\interfaces\CborTag;
use Closure;
use SurrealDB\Codec\Cbor\CborValueMapper;
use SurrealDB\Exceptions\SerializationException;

/**
 * The tag handlers for the SurrealDB value types, for the `tags` option of
 * a {@see \Beau\CborPHP\Cbor} instance.
 *
 * @internal
 */
final class SurrealTags
{
	/**
	 * Every handler: what the serializer needs to write each SurrealDB value.
	 *
	 * @return list<CborTag>
	 */
	public static function all(): array
	{
		return [
			new NoneTag(),
			new TableTag(),
			new RecordIdTag(),
			new StringUuidTag(),
			new UuidTag(),
			new DecimalTag(),
			new StringDateTimeTag(),
			new DateTimeTag(),
			new StringDurationTag(),
			new DurationTag(),
			new FutureTag(),
			new RangeTag(),
			new BoundIncludedTag(),
			new BoundExcludedTag(),
			new FileTag(),
			new SetTag(),
			new GeometryPointTag(),
			new GeometryLineTag(),
			new GeometryPolygonTag(),
			new GeometryMultiPointTag(),
			new GeometryMultiLineTag(),
			new GeometryMultiPolygonTag(),
			new GeometryCollectionTag(),
		];
	}

	/**
	 * The handlers the deserializer registers: all but the containers, which
	 * {@see self::reviver()} reads. See {@see ContainerTag}.
	 *
	 * @return list<CborTag>
	 */
	public static function decoders(): array
	{
		return array_values(array_filter(
			self::all(),
			static fn (CborTag $tag): bool => !$tag instanceof ContainerTag,
		));
	}

	/**
	 * The reviver for the deserializer: it reads each container tag, and
	 * refuses any other tag no handler took.
	 *
	 * @return \Closure(TaggedValue): mixed
	 */
	public static function reviver(): \Closure
	{
		$containers = [];

		foreach (self::all() as $tag) {
			if ($tag instanceof ContainerTag) {
				$containers[$tag->tag()] = $tag;
			}
		}

		return static function (TaggedValue $tagged) use ($containers): mixed {
			$handler = $containers[$tagged->tag] ?? throw new SerializationException(
				"Serialization",
				"Unsupported SurrealDB CBOR tag: " . $tagged->tag,
			);

			return $handler->decode(CborValueMapper::decode($tagged->value));
		};
	}
}
