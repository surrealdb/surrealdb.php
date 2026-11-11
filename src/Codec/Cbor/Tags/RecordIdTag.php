<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Codec\Cbor\CborValueMapper;
use SurrealDB\SDK\Types\Range;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\StringRecordId;
use function is_string;

/**
 * A record ID: tag 8 with [table, id], where the id can be a range, or with
 * the whole ID as text.
 *
 * cbor.php takes one class for each tag number, so this handler encodes
 * RecordId only. {@see CborValueMapper::encode()} tags a RecordIdRange and a
 * StringRecordId with {@see self::content()}.
 */
final class RecordIdTag implements ContainerTag
{
	public function tag(): int
	{
		return Tag::RECORD_ID->value;
	}

	public function class(): string
	{
		return RecordId::class;
	}

	/**
	 * @param RecordId<string> $value
	 */
	public function encode(object $value, CborContext $context): mixed
	{
		return self::content($value);
	}

	/**
	 * @return RecordId<string>|RecordIdRange<string>|StringRecordId
	 */
	public function decode(mixed $content, ?CborContext $context = null): RecordId|RecordIdRange|StringRecordId
	{
		if (is_string($content)) {
			return new StringRecordId($content);
		}

		$parts = TagContent::items($content);
		$table = (string) ($parts[0] ?? "");
		$id = $parts[1] ?? null;

		if ($id instanceof Range) {
			return new RecordIdRange($table, $id->begin, $id->end);
		}

		return new RecordId($table, $id);
	}

	/**
	 * The tag content of any of the three record ID types.
	 *
	 * @param RecordId<string>|RecordIdRange<string>|StringRecordId $value
	 */
	public static function content(RecordId|RecordIdRange|StringRecordId $value): mixed
	{
		return match (true) {
			$value instanceof StringRecordId => $value->id,
			$value instanceof RecordIdRange => [$value->table, new Range($value->begin, $value->end)],
			default => [$value->table, CborValueMapper::encode($value->id)],
		};
	}
}
