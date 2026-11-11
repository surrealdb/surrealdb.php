<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Codec\Cbor\CborValueMapper;
use SurrealDB\Types\Set;

/**
 * A set: tag 56 with its values as an array.
 */
final class SetTag implements ContainerTag
{
	public function tag(): int
	{
		return Tag::SET->value;
	}

	public function class(): string
	{
		return Set::class;
	}

	/**
	 * @param Set<mixed> $value
	 * @return list<mixed>
	 */
	public function encode(object $value, CborContext $context): array
	{
		return array_map(CborValueMapper::encode(...), $value->values);
	}

	/**
	 * @return Set<mixed>
	 */
	public function decode(mixed $content, ?CborContext $context = null): Set
	{
		return new Set(TagContent::items($content));
	}
}
