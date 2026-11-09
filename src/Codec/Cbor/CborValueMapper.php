<?php

namespace SurrealDB\SDK\Codec\Cbor;

use Beau\CborPHP\classes\TaggedValue;
use Beau\CborPHP\utils\CborByteString;
use Beau\CborPHP\utils\CborMap;
use JsonSerializable;
use SurrealDB\SDK\Exceptions\SerializationException;
use SurrealDB\SDK\Types\BoundExcluded;
use SurrealDB\SDK\Types\BoundIncluded;
use SurrealDB\SDK\Types\Bytes;
use SurrealDB\SDK\Types\DateTime;
use SurrealDB\SDK\Types\Decimal;
use SurrealDB\SDK\Types\Duration;
use SurrealDB\SDK\Types\File;
use SurrealDB\SDK\Types\Future;
use SurrealDB\SDK\Types\Geometry;
use SurrealDB\SDK\Types\GeometryCollection;
use SurrealDB\SDK\Types\GeometryLine;
use SurrealDB\SDK\Types\GeometryMultiLine;
use SurrealDB\SDK\Types\GeometryMultiPoint;
use SurrealDB\SDK\Types\GeometryMultiPolygon;
use SurrealDB\SDK\Types\GeometryPoint;
use SurrealDB\SDK\Types\GeometryPolygon;
use SurrealDB\SDK\Types\None;
use SurrealDB\SDK\Types\Range;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\Set;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;
use SurrealDB\SDK\Types\Uuid;
use function is_array;
use function is_string;
use function strlen;

final class CborValueMapper
{
	/**
	 * SurrealDB decimals hold at most 28 digits after the point.
	 */
	private const int DECIMAL_SCALE = 28;

	/**
	 * The most digits a decoded decimal may have. SurrealDB decimals have at
	 * most 29 significant digits; this leaves room to spare, and keeps
	 * brick/math from building a huge number out of hostile text.
	 */
	private const int MAX_DECIMAL_DIGITS = 64;

	public static function encode(mixed $data): mixed
	{
		return match (true) {
			$data instanceof None => self::tag(CborTag::NONE, null),
			$data instanceof Table => self::tag(CborTag::TABLE, $data->name),
			$data instanceof StringRecordId => self::tag(
				CborTag::RECORD_ID,
				$data->id,
			),
			$data instanceof RecordId => self::tag(CborTag::RECORD_ID, [
				$data->table,
				self::encode($data->id),
			]),
			$data instanceof RecordIdRange => self::tag(CborTag::RECORD_ID, [
				$data->table,
				self::encodeRange($data->begin, $data->end),
			]),
			$data instanceof Decimal => self::tag(
				CborTag::DECIMAL,
				self::decimalText($data),
			),
			$data instanceof DateTime => self::tag(
				CborTag::CUSTOM_DATETIME,
				$data->toCompact(),
			),
			$data instanceof Duration => self::tag(
				CborTag::CUSTOM_DURATION,
				$data->toCompact(),
			),
			$data instanceof Future => self::tag(CborTag::FUTURE, $data->body),
			// The 16-byte form (tag 37) is the one every server accepts:
			// SurrealDB 4.x rejects the text form (tag 9), as the JS SDK
			// never sends it.
			$data instanceof Uuid => self::tag(
				CborTag::SPEC_UUID,
				new CborByteString($data->toBytes()),
			),
			$data instanceof Bytes => new CborByteString($data->bytes),
			$data instanceof File => self::tag(CborTag::FILE, [
				$data->bucket,
				$data->key,
			]),
			$data instanceof Set => self::tag(
				CborTag::SET,
				array_map(self::encode(...), $data->values),
			),
			$data instanceof Range => self::encodeRange($data->begin, $data->end),
			$data instanceof BoundIncluded, $data instanceof BoundExcluded => self::encodeBound($data),
			$data instanceof Geometry => self::encodeGeometry($data),
			is_string($data) => self::utf8($data),
			is_array($data) && array_is_list($data) => array_map(self::encode(...), $data),
			is_array($data) => self::encodeMap($data),
			$data instanceof \JsonSerializable => self::encode(
				$data->jsonSerialize(),
			),
			$data instanceof \stdClass => self::encodeMap(get_object_vars($data)),
			default => $data,
		};
	}

	public static function decode(mixed $data): mixed
	{
		if ($data instanceof TaggedValue) {
			return self::decodeTagged($data);
		}

		if ($data instanceof CborByteString) {
			return new Bytes($data->getByteString());
		}

		if (is_array($data)) {
			return array_map(self::decode(...), $data);
		}

		return $data;
	}

	private static function tag(CborTag $tag, mixed $value): TaggedValue
	{
		return new TaggedValue($tag->value, $value);
	}

	/**
	 * @param BoundIncluded<mixed>|BoundExcluded<mixed>|null $begin
	 * @param BoundIncluded<mixed>|BoundExcluded<mixed>|null $end
	 */
	private static function encodeRange(
		BoundIncluded|BoundExcluded|null $begin,
		BoundIncluded|BoundExcluded|null $end,
	): TaggedValue {
		return self::tag(CborTag::RANGE, [
			self::encodeBound($begin),
			self::encodeBound($end),
		]);
	}

	/**
	 * @param BoundIncluded<mixed>|BoundExcluded<mixed>|null $bound
	 */
	private static function encodeBound(
		BoundIncluded|BoundExcluded|null $bound,
	): ?TaggedValue {
		return match (true) {
			$bound instanceof BoundIncluded => self::tag(
				CborTag::BOUND_INCLUDED,
				self::encode($bound->value),
			),
			$bound instanceof BoundExcluded => self::tag(
				CborTag::BOUND_EXCLUDED,
				self::encode($bound->value),
			),
			default => null,
		};
	}

	private static function encodeGeometry(Geometry $geometry): TaggedValue
	{
		return match (true) {
			$geometry instanceof GeometryPoint => self::tag(
				CborTag::GEOMETRY_POINT,
				[$geometry->longitude, $geometry->latitude],
			),
			$geometry instanceof GeometryLine => self::tag(
				CborTag::GEOMETRY_LINE,
				array_map(self::encodeGeometry(...), $geometry->points),
			),
			$geometry instanceof GeometryPolygon => self::tag(
				CborTag::GEOMETRY_POLYGON,
				array_map(self::encodeGeometry(...), $geometry->rings),
			),
			$geometry instanceof GeometryMultiPoint => self::tag(
				CborTag::GEOMETRY_MULTIPOINT,
				array_map(self::encodeGeometry(...), $geometry->points),
			),
			$geometry instanceof GeometryMultiLine => self::tag(
				CborTag::GEOMETRY_MULTILINE,
				array_map(self::encodeGeometry(...), $geometry->lines),
			),
			$geometry instanceof GeometryMultiPolygon => self::tag(
				CborTag::GEOMETRY_MULTIPOLYGON,
				array_map(self::encodeGeometry(...), $geometry->polygons),
			),
			$geometry instanceof GeometryCollection => self::tag(
				CborTag::GEOMETRY_COLLECTION,
				array_map(self::encodeGeometry(...), $geometry->geometries),
			),
			default => throw new SerializationException(
				"Serialization",
				"Unsupported geometry type: " . $geometry::class,
			),
		};
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
				'Text sent to SurrealDB must be valid UTF-8; send binary data as a SurrealDB\\SDK\\Types\\Bytes value.',
			);
		}

		return $text;
	}

	private static function decodeTagged(TaggedValue $tagged): mixed
	{
		return match (CborTag::tryFrom($tagged->tag)) {
			CborTag::SPEC_DATETIME => DateTime::fromString(
				self::text($tagged->value),
			),
			CborTag::NONE => None::instance(),
			CborTag::TABLE => new Table(self::text($tagged->value)),
			CborTag::RECORD_ID => self::decodeRecordId($tagged->value),
			CborTag::STRING_UUID => Uuid::fromString(self::text($tagged->value)),
			CborTag::DECIMAL => self::decodeDecimal($tagged->value),
			CborTag::CUSTOM_DATETIME => self::decodeDateTime($tagged->value),
			CborTag::STRING_DURATION => Duration::fromString(
				self::text($tagged->value),
			),
			CborTag::CUSTOM_DURATION => self::decodeDuration($tagged->value),
			CborTag::FUTURE => new Future(self::text($tagged->value)),
			CborTag::SPEC_UUID => self::decodeUuid($tagged->value),
			CborTag::RANGE => self::decodeRange($tagged->value),
			CborTag::BOUND_INCLUDED => new BoundIncluded(
				self::decode($tagged->value),
			),
			CborTag::BOUND_EXCLUDED => new BoundExcluded(
				self::decode($tagged->value),
			),
			CborTag::FILE => self::decodeFile($tagged->value),
			CborTag::SET => new Set(
				array_map(self::decode(...), self::listValue($tagged->value)),
			),
			CborTag::GEOMETRY_POINT => self::decodePoint($tagged->value),
			CborTag::GEOMETRY_LINE => self::decodeLine($tagged->value),
			CborTag::GEOMETRY_POLYGON => self::decodePolygon($tagged->value),
			CborTag::GEOMETRY_MULTIPOINT => new GeometryMultiPoint(
				...array_map(
					self::pointValue(...),
					self::listValue($tagged->value),
				),
			),
			CborTag::GEOMETRY_MULTILINE => new GeometryMultiLine(
				...array_map(
					self::lineValue(...),
					self::listValue($tagged->value),
				),
			),
			CborTag::GEOMETRY_MULTIPOLYGON => new GeometryMultiPolygon(
				...array_map(
					self::polygonValue(...),
					self::listValue($tagged->value),
				),
			),
			CborTag::GEOMETRY_COLLECTION => new GeometryCollection(
				...array_map(
					self::geometryValue(...),
					self::listValue($tagged->value),
				),
			),
			default => throw new SerializationException(
				"Serialization",
				"Unsupported SurrealDB CBOR tag: " . $tagged->tag,
			),
		};
	}

	/**
	 * @return RecordId<string>|RecordIdRange<string>|StringRecordId
	 */
	private static function decodeRecordId(
		mixed $value,
	): RecordId|RecordIdRange|StringRecordId {
		if (is_string($value)) {
			return new StringRecordId($value);
		}

		$parts = self::listValue($value);
		$table = (string) ($parts[0] ?? "");
		$id = self::decode($parts[1] ?? null);

		if ($id instanceof Range) {
			return new RecordIdRange($table, $id->begin, $id->end);
		}

		return new RecordId($table, $id);
	}

	/**
	 * The decimal's text with any zeros past {@see self::DECIMAL_SCALE}
	 * digits after the point dropped, which leaves the number the same:
	 * 0.000000000000000000000000000000 is sent with 28 zeros. No other digit
	 * is dropped.
	 */
	private static function decimalText(Decimal $decimal): string
	{
		$text = (string) $decimal;
		$point = strpos($text, ".");

		if ($point === false) {
			return $text;
		}

		$excess = strlen($text) - $point - 1 - self::DECIMAL_SCALE;

		if ($excess <= 0) {
			return $text;
		}

		return substr($text, 0, -$excess) . rtrim(substr($text, -$excess), "0");
	}

	/**
	 * A decimal from tag 10, whose text is checked before brick/math reads
	 * it: an exponent such as 1e999999999 would exhaust memory, which no
	 * caller can catch, and text brick/math refuses would escape as its own
	 * exception.
	 */
	private static function decodeDecimal(mixed $value): Decimal
	{
		$text = self::text($value);
		$valid = preg_match(
			'/^[+-]?([0-9]+)(?:\.([0-9]+))?(?:[eE][+-]?[0-9]{1,2})?$/D',
			$text,
			$matches,
		) === 1;

		if (!$valid || strlen($matches[1]) + strlen($matches[2] ?? "") > self::MAX_DECIMAL_DIGITS) {
			throw new SerializationException(
				"Serialization",
				"Invalid decimal in a SurrealDB CBOR tag: " . self::excerpt($text),
			);
		}

		return new Decimal($text);
	}

	/**
	 * At most 64 bytes of untrusted text, safe to put in a message.
	 */
	private static function excerpt(string $text): string
	{
		$excerpt = json_encode(substr($text, 0, 64), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

		return ($excerpt === false ? "\"\"" : $excerpt) . (strlen($text) > 64 ? "..." : "");
	}

	private static function decodeDateTime(mixed $value): DateTime
	{
		$parts = self::listValue($value);

		return new DateTime((int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0));
	}

	private static function decodeDuration(mixed $value): Duration
	{
		$parts = self::listValue($value);

		return new Duration((int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0));
	}

	private static function decodeUuid(mixed $value): Uuid
	{
		if ($value instanceof CborByteString && strlen($value->getByteString()) === 16) {
			return Uuid::fromBytes($value->getByteString());
		}

		return Uuid::fromString(self::text($value));
	}

	/**
	 * The text a tag carries. A byte string is taken as its bytes; anything
	 * else is malformed.
	 */
	private static function text(mixed $value): string
	{
		return match (true) {
			is_string($value) => $value,
			$value instanceof CborByteString => $value->getByteString(),
			default => throw new SerializationException(
				"Serialization",
				"Expected text in a SurrealDB CBOR tag, got " . get_debug_type($value),
			),
		};
	}

	/**
	 * @return Range<mixed,mixed>
	 */
	private static function decodeRange(mixed $value): Range
	{
		$parts = self::listValue($value);
		$begin = self::decode($parts[0] ?? null);
		$end = self::decode($parts[1] ?? null);

		return new Range(
			$begin instanceof BoundIncluded || $begin instanceof BoundExcluded
				? $begin
				: null,
			$end instanceof BoundIncluded || $end instanceof BoundExcluded
				? $end
				: null,
		);
	}

	private static function decodeFile(mixed $value): File
	{
		$parts = self::listValue($value);

		return new File((string) ($parts[0] ?? ""), (string) ($parts[1] ?? ""));
	}

	private static function decodePoint(mixed $value): GeometryPoint
	{
		$point = self::listValue($value);

		return new GeometryPoint(
			(float) ($point[0] ?? 0.0),
			(float) ($point[1] ?? 0.0),
		);
	}

	private static function decodeLine(mixed $value): GeometryLine
	{
		return new GeometryLine(
			...array_map(self::pointValue(...), self::listValue($value)),
		);
	}

	private static function decodePolygon(mixed $value): GeometryPolygon
	{
		return new GeometryPolygon(
			...array_map(self::lineValue(...), self::listValue($value)),
		);
	}

	private static function pointValue(mixed $value): GeometryPoint
	{
		$decoded = self::decode($value);

		return $decoded instanceof GeometryPoint
			? $decoded
			: self::decodePoint($decoded);
	}

	private static function lineValue(mixed $value): GeometryLine
	{
		$decoded = self::decode($value);

		return $decoded instanceof GeometryLine
			? $decoded
			: self::decodeLine($decoded);
	}

	private static function polygonValue(mixed $value): GeometryPolygon
	{
		$decoded = self::decode($value);

		return $decoded instanceof GeometryPolygon
			? $decoded
			: self::decodePolygon($decoded);
	}

	private static function geometryValue(mixed $value): Geometry
	{
		$decoded = self::decode($value);

		if (!$decoded instanceof Geometry) {
			throw new SerializationException(
				"Serialization",
				"Expected a tagged geometry value in CBOR geometry collection.",
			);
		}

		return $decoded;
	}

	/**
	 * @return list<mixed>
	 */
	private static function listValue(mixed $value): array
	{
		return is_array($value) ? array_values($value) : [];
	}
}
