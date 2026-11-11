<?php

namespace SurrealDB\Codec\Cbor;

use Beau\CborPHP\classes\TaggedValue;
use Beau\CborPHP\utils\CborByteString;
use Beau\CborPHP\utils\CborMap;
use JsonSerializable;
use stdClass;
use SurrealDB\Codec\Cbor\Tags\RecordIdTag;
use SurrealDB\Exceptions\SerializationException;
use SurrealDB\Types\BoundExcluded;
use SurrealDB\Types\BoundIncluded;
use SurrealDB\Types\Bytes;
use SurrealDB\Types\RecordIdRange;
use SurrealDB\Types\StringRecordId;
use SurrealDB\Types\Value;
use function is_array;
use function is_string;

/**
 * The walk around cbor.php for what its tag handlers do not cover: text
 * checked as UTF-8, PHP arrays written as SurrealDB arrays and objects, and
 * the objects that have no handler of their own. The SurrealDB value types
 * are written and read by their handlers in {@see Tags}.
 */
final class CborValueMapper
{
	public static function encode(mixed $data): mixed
	{
		return match (true) {
			is_string($data) => self::utf8($data),
			is_array($data) && array_is_list($data) => array_map(self::encode(...), $data),
			is_array($data) => self::encodeMap($data),
			$data instanceof Bytes => new CborByteString($data->bytes),
			// cbor.php takes one class for each tag number; tag 8 has three.
			$data instanceof StringRecordId, $data instanceof RecordIdRange => new TaggedValue(
				CborTag::RECORD_ID->value,
				RecordIdTag::content($data),
			),
			// Written by their tag handlers, not as their JSON form.
			$data instanceof Value, $data instanceof BoundIncluded, $data instanceof BoundExcluded => $data,
			$data instanceof \JsonSerializable => self::encode($data->jsonSerialize()),
			$data instanceof \stdClass => self::encodeMap(get_object_vars($data)),
			default => $data,
		};
	}

	/**
	 * Byte strings, which cbor.php reads as CborByteString, become Bytes.
	 * Tagged values are already SurrealDB values.
	 */
	public static function decode(mixed $data): mixed
	{
		if ($data instanceof CborByteString) {
			return new Bytes($data->getByteString());
		}

		if (is_array($data)) {
			return array_map(self::decode(...), $data);
		}

		return $data;
	}

	/**
	 * An object. SurrealDB objects have text keys only, and PHP stores a key
	 * such as '2024' as the int 2024, so int keys are written as text; an
	 * empty object stays a map ({}), which an empty array would not.
	 *
	 * @param array<mixed> $data
	 */
	private static function encodeMap(array $data): CborMap
	{
		$encoded = [];

		foreach ($data as $key => $value) {
			if (is_string($key)) {
				self::utf8($key);
			}

			$encoded[$key] = self::encode($value);
		}

		return new CborMap($encoded, stringKeys: true);
	}

	/**
	 * SurrealDB refuses text that is not UTF-8, saying only "Parse error":
	 * binary data goes as a byte string.
	 */
	private static function utf8(string $text): string
	{
		if (preg_match('//u', $text) !== 1) {
			throw new SerializationException(
				'Serialization',
				'Text sent to SurrealDB must be valid UTF-8; send binary data as a SurrealDB\\Types\\Bytes value.',
			);
		}

		return $text;
	}
}
