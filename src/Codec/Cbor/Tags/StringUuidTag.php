<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use LogicException;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Types\Uuid;

/**
 * A UUID as text: tag 9. Read only; a Uuid is written as tag 37
 * ({@see UuidTag}).
 */
final class StringUuidTag implements CborTag
{
	public function tag(): int
	{
		return Tag::STRING_UUID->value;
	}

	public function class(): ?string
	{
		return null;
	}

	public function encode(object $value, CborContext $context): never
	{
		throw new \LogicException(self::class . ' only decodes.');
	}

	public function decode(mixed $content, CborContext $context): Uuid
	{
		return Uuid::fromString(TagContent::text($content));
	}
}
