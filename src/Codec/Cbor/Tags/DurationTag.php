<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Types\Duration;

/**
 * A duration: tag 14 with [seconds, nanoseconds].
 */
final class DurationTag implements CborTag
{
	public function tag(): int
	{
		return Tag::CUSTOM_DURATION->value;
	}

	public function class(): string
	{
		return Duration::class;
	}

	/**
	 * @param Duration $value
	 */
	public function encode(object $value, CborContext $context): mixed
	{
		return $value->toCompact();
	}

	public function decode(mixed $content, CborContext $context): Duration
	{
		$parts = TagContent::items($content);

		return new Duration((int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0));
	}
}
