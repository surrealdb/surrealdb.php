<?php

namespace SurrealDB\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\Codec\Cbor\CborTag as Tag;
use SurrealDB\Types\DateTime;

/**
 * A datetime: tag 12 with [seconds, nanoseconds].
 */
final class DateTimeTag implements CborTag
{
	public function tag(): int
	{
		return Tag::CUSTOM_DATETIME->value;
	}

	public function class(): string
	{
		return DateTime::class;
	}

	/**
	 * @param DateTime $value
	 */
	public function encode(object $value, CborContext $context): mixed
	{
		return $value->toCompact();
	}

	public function decode(mixed $content, CborContext $context): DateTime
	{
		$parts = TagContent::items($content);

		return new DateTime((int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0));
	}
}
