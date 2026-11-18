<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Codec\Cbor\CborValueMapper;
use SurrealDB\Types\BoundIncluded;

/**
 * An included range bound: tag 50 with the bound value.
 */
final class BoundIncludedTag implements ContainerTag
{
	public function tag(): int
	{
		return Tag::BOUND_INCLUDED->value;
	}

	public function class(): string
	{
		return BoundIncluded::class;
	}

	/**
	 * @param BoundIncluded<mixed> $value
	 */
	public function encode(object $value, CborContext $context): mixed
	{
		return CborValueMapper::encode($value->value);
	}

	/**
	 * @return BoundIncluded<mixed>
	 */
	public function decode(mixed $content, ?CborContext $context = null): BoundIncluded
	{
		return new BoundIncluded($content);
	}
}
