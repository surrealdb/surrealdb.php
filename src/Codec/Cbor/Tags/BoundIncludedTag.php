<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Codec\Cbor\CborValueMapper;
use SurrealDB\SDK\Types\BoundIncluded;

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
