<?php

namespace SurrealDB\Codec\Cbor;

use Beau\CborPHP\classes\TaggedValue;
use Beau\CborPHP\utils\CborByteString;
use JsonSerializable;
use SurrealDB\Exceptions\SerializationException;
use SurrealDB\Types\BoundExcluded;
use SurrealDB\Types\BoundIncluded;
use SurrealDB\Types\Bytes;
use SurrealDB\Types\DateTime;
use SurrealDB\Types\Decimal;
use SurrealDB\Types\Duration;
use SurrealDB\Types\File;
use SurrealDB\Types\Future;
use SurrealDB\Types\Geometry;
use SurrealDB\Types\GeometryCollection;
use SurrealDB\Types\GeometryLine;
use SurrealDB\Types\GeometryMultiLine;
use SurrealDB\Types\GeometryMultiPoint;
use SurrealDB\Types\GeometryMultiPolygon;
use SurrealDB\Types\GeometryPoint;
use SurrealDB\Types\GeometryPolygon;
use SurrealDB\Types\None;
use SurrealDB\Types\Range;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\RecordIdRange;
use SurrealDB\Types\Set;
use SurrealDB\Types\StringRecordId;
use SurrealDB\Types\Table;
use SurrealDB\Types\Uuid;
use function is_array;
use function is_string;
use function sprintf;
use function strlen;

final class CborValueMapper
{
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
			$data instanceof RecordIdRange => self::unsupportedHighTag(
				CborTag::RANGE,
			),
			$data instanceof Decimal => self::tag(
				CborTag::DECIMAL,
				(string) $data,
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
			$data instanceof Uuid => self::tag(
				CborTag::STRING_UUID,
				(string) $data,
			),
			$data instanceof Bytes => new CborByteString($data->bytes),
			$data instanceof File => self::unsupportedHighTag(CborTag::FILE),
			$data instanceof Set => self::unsupportedHighTag(CborTag::SET),
			$data instanceof Range => self::unsupportedHighTag(CborTag::RANGE),
			$data instanceof BoundIncluded => self::unsupportedHighTag(
				CborTag::BOUND_INCLUDED,
			),
			$data instanceof BoundExcluded => self::unsupportedHighTag(
				CborTag::BOUND_EXCLUDED,
			),
			$data instanceof GeometryPoint => self::unsupportedHighTag(
				CborTag::GEOMETRY_POINT,
			),
			$data instanceof GeometryLine => self::unsupportedHighTag(
				CborTag::GEOMETRY_LINE,
			),
			$data instanceof GeometryPolygon => self::unsupportedHighTag(
				CborTag::GEOMETRY_POLYGON,
			),
			$data instanceof GeometryMultiPoint => self::unsupportedHighTag(
				CborTag::GEOMETRY_MULTIPOINT,
			),
			$data instanceof GeometryMultiLine => self::unsupportedHighTag(
				CborTag::GEOMETRY_MULTILINE,
			),
			$data instanceof GeometryMultiPolygon => self::unsupportedHighTag(
				CborTag::GEOMETRY_MULTIPOLYGON,
			),
			$data instanceof GeometryCollection => self::unsupportedHighTag(
				CborTag::GEOMETRY_COLLECTION,
			),
			is_array($data) => self::encodeArray($data),
			$data instanceof \JsonSerializable => self::encode(
				$data->jsonSerialize(),
			),
			$data instanceof \stdClass => self::encode(get_object_vars($data)),
			default => $data,
		};
	}

	public static function decode(mixed $data): mixed
	{
		if ($data instanceof TaggedValue) {
			return self::decodeTagged($data);
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

	private static function unsupportedHighTag(CborTag $tag): never
	{
		throw new SerializationException(
			"Serialization",
			sprintf(
				"welpie21/cbor.php cannot encode SurrealDB CBOR tag %d correctly; refusing to emit malformed CBOR.",
				$tag->value,
			),
		);
	}

	/**
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	private static function encodeArray(array $data): array
	{
		$encoded = [];

		foreach ($data as $key => $value) {
			$encoded[$key] = self::encode($value);
		}

		return $encoded;
	}

	private static function decodeTagged(TaggedValue $tagged): mixed
	{
		return match (CborTag::tryFrom($tagged->tag)) {
			CborTag::SPEC_DATETIME => DateTime::fromString(
				(string) $tagged->value,
			),
			CborTag::NONE => None::instance(),
			CborTag::TABLE => new Table((string) $tagged->value),
			CborTag::RECORD_ID => self::decodeRecordId($tagged->value),
			CborTag::STRING_UUID => Uuid::fromString((string) $tagged->value),
			CborTag::DECIMAL => new Decimal((string) $tagged->value),
			CborTag::CUSTOM_DATETIME => self::decodeDateTime($tagged->value),
			CborTag::STRING_DURATION => Duration::fromString(
				(string) $tagged->value,
			),
			CborTag::CUSTOM_DURATION => self::decodeDuration($tagged->value),
			CborTag::FUTURE => new Future((string) $tagged->value),
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
		if (is_string($value) && strlen($value) === 16) {
			return Uuid::fromBytes($value);
		}

		return Uuid::fromString((string) $value);
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
