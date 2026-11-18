<?php

namespace SurrealDB\Codec\Cbor\Tags;

use SurrealDB\Exceptions\SerializationException;
use SurrealDB\Types\Bytes;
use function is_array;
use function is_string;
use function strlen;

/**
 * Checks shared by the tag handlers. Tag content is whatever the sender
 * wrote, so a handler checks its shape before it builds a value.
 *
 * @internal
 */
final class TagContent
{
	/**
	 * The text a tag carries. A byte string is taken as its bytes; anything
	 * else is malformed.
	 */
	public static function text(mixed $content): string
	{
		return match (true) {
			is_string($content) => $content,
			$content instanceof Bytes => $content->bytes,
			default => throw new SerializationException(
				"Serialization",
				"Expected text in a SurrealDB CBOR tag, got " . get_debug_type($content),
			),
		};
	}

	/**
	 * The items a tag carries, as a list: safe to spread into a constructor,
	 * which a map would fill by name.
	 *
	 * @return list<mixed>
	 */
	public static function items(mixed $content): array
	{
		return is_array($content) ? array_values($content) : [];
	}

	/**
	 * At most 64 bytes of untrusted text, safe to put in a message.
	 */
	public static function excerpt(string $text): string
	{
		$excerpt = json_encode(substr($text, 0, 64), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

		return ($excerpt === false ? "\"\"" : $excerpt) . (strlen($text) > 64 ? "..." : "");
	}
}
