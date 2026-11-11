<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use LogicException;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Types\DateTime;

/**
 * A datetime as RFC 3339 text: tag 0. Read only; a DateTime is written as
 * tag 12 ({@see DateTimeTag}).
 */
final class StringDateTimeTag implements CborTag
{
	public function tag(): int
	{
		return Tag::SPEC_DATETIME->value;
	}

	public function class(): ?string
	{
		return null;
	}

	public function encode(object $value, CborContext $context): never
	{
		throw new \LogicException(self::class . ' only decodes.');
	}

	public function decode(mixed $content, CborContext $context): DateTime
	{
		return DateTime::fromString(TagContent::text($content));
	}
}
