<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use Beau\CborPHP\utils\CborByteString;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Types\Uuid;
use function strlen;

/**
 * A UUID: tag 37 with its 16 bytes.
 *
 * This is the form every server accepts: SurrealDB 4.x rejects the text
 * form (tag 9, {@see StringUuidTag}), as the JS SDK never sends it.
 */
final class UuidTag implements CborTag
{
	public function tag(): int
	{
		return Tag::SPEC_UUID->value;
	}

	public function class(): string
	{
		return Uuid::class;
	}

	/**
	 * @param Uuid $value
	 */
	public function encode(object $value, CborContext $context): CborByteString
	{
		return new CborByteString($value->toBytes());
	}

	/**
	 * The content is a plain string: 16 bytes, or else the UUID as text.
	 */
	public function decode(mixed $content, CborContext $context): Uuid
	{
		$text = TagContent::text($content);

		return strlen($text) === 16 ? Uuid::fromBytes($text) : Uuid::fromString($text);
	}
}
