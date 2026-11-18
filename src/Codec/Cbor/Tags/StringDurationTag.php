<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use LogicException;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Types\Duration;

/**
 * A duration as text, such as "1h30m": tag 13. Read only; a Duration is
 * written as tag 14 ({@see DurationTag}).
 */
final class StringDurationTag implements CborTag
{
	public function tag(): int
	{
		return Tag::STRING_DURATION->value;
	}

	public function class(): ?string
	{
		return null;
	}

	public function encode(object $value, CborContext $context): never
	{
		throw new \LogicException(self::class . ' only decodes.');
	}

	public function decode(mixed $content, CborContext $context): Duration
	{
		return Duration::fromString(TagContent::text($content));
	}
}
