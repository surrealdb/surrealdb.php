<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Types\Future;

/**
 * A future: tag 15 with its body as text.
 */
final class FutureTag implements CborTag
{
	public function tag(): int
	{
		return Tag::FUTURE->value;
	}

	public function class(): string
	{
		return Future::class;
	}

	/**
	 * @param Future $value
	 */
	public function encode(object $value, CborContext $context): string
	{
		return $value->body;
	}

	public function decode(mixed $content, CborContext $context): Future
	{
		return new Future(TagContent::text($content));
	}
}
