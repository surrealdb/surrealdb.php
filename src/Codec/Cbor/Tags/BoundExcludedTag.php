<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Codec\Cbor\CborValueMapper;
use SurrealDB\Types\BoundExcluded;

/**
 * An excluded range bound: tag 51 with the bound value.
 */
final class BoundExcludedTag implements ContainerTag
{
	public function tag(): int
	{
		return Tag::BOUND_EXCLUDED->value;
	}

	public function class(): string
	{
		return BoundExcluded::class;
	}

	/**
	 * @param BoundExcluded<mixed> $value
	 */
	public function encode(object $value, CborContext $context): mixed
	{
		return CborValueMapper::encode($value->value);
	}

	/**
	 * @return BoundExcluded<mixed>
	 */
	public function decode(mixed $content, ?CborContext $context = null): BoundExcluded
	{
		return new BoundExcluded($content);
	}
}
