<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Types\File;

/**
 * A file reference: tag 55 with [bucket, key].
 */
final class FileTag implements CborTag
{
	public function tag(): int
	{
		return Tag::FILE->value;
	}

	public function class(): string
	{
		return File::class;
	}

	/**
	 * @param File $value
	 * @return array{string, string}
	 */
	public function encode(object $value, CborContext $context): array
	{
		return [$value->bucket, $value->key];
	}

	public function decode(mixed $content, CborContext $context): File
	{
		$parts = TagContent::items($content);

		return new File((string) ($parts[0] ?? ""), (string) ($parts[1] ?? ""));
	}
}
