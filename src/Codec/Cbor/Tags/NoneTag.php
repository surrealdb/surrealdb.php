<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Types\None;

/**
 * NONE: tag 6 with null.
 */
final class NoneTag implements CborTag
{
	public function tag(): int
	{
		return Tag::NONE->value;
	}

	public function class(): string
	{
		return None::class;
	}

	public function encode(object $value, CborContext $context): mixed
	{
		return null;
	}

	public function decode(mixed $content, CborContext $context): None
	{
		return None::instance();
	}
}
