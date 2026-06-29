<?php

namespace SurrealDB\SDK\Codec;

use SurrealDB\SDK\Types\BoundExcluded;
use SurrealDB\SDK\Types\BoundIncluded;
use SurrealDB\SDK\Types\Bytes;
use SurrealDB\SDK\Types\DateTime;
use SurrealDB\SDK\Types\Decimal;
use SurrealDB\SDK\Types\Duration;
use SurrealDB\SDK\Types\File;
use SurrealDB\SDK\Types\Future;
use SurrealDB\SDK\Types\Geometry;
use SurrealDB\SDK\Types\None;
use SurrealDB\SDK\Types\Range;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\Set;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;
use SurrealDB\SDK\Types\Uuid;
use SurrealDB\SDK\Types\Value;
use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Translates between SurrealDB's SQON-J tagged JSON form (e.g.
 * `{"$datetime": "..."}`) and the SDK's {@see Value} type instances.
 *
 * The {@see Value} types already serialize to SQON-J via `JsonSerializable`, so
 * encoding only needs to flatten a value tree to plain PHP arrays. Decoding is
 * the inverse: it walks a decoded JSON tree and reconstructs typed instances
 * wherever it finds a recognised tag.
 *
 * Note: over the plain JSON wire protocol SurrealDB does not emit these tags
 * (typed values arrive as bare strings/objects), so this mapper is primarily
 * useful for round-tripping SQON-J payloads. Lossless typed decoding of every
 * response requires the CBOR protocol.
 */
final class ValueMapper
{
    /**
     * Flatten a value tree (which may contain {@see Value} instances) into a
     * plain SQON-J array/scalar tree.
     */
    public static function encode(mixed $data): mixed
    {
        if ($data instanceof Value || $data instanceof BoundIncluded || $data instanceof BoundExcluded) {
            return self::encode($data->jsonSerialize());
        }

        if (is_array($data)) {
            return array_map(self::encode(...), $data);
        }

        return $data;
    }

    /**
     * Reconstruct {@see Value} instances from a decoded SQON-J tree.
     */
    public static function decode(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        if (array_is_list($data)) {
            return array_map(self::decode(...), $data);
        }

        if (self::isTag($data, '$none')) {
            return None::instance();
        }

        if (self::isTag($data, '$datetime') && is_string($data['$datetime'])) {
            return DateTime::fromString($data['$datetime']);
        }

        if (self::isTag($data, '$decimal') && is_string($data['$decimal'])) {
            return new Decimal($data['$decimal']);
        }

        if (self::isTag($data, '$duration') && is_string($data['$duration'])) {
            return Duration::fromString($data['$duration']);
        }

        if (self::isTag($data, '$uuid') && is_string($data['$uuid'])) {
            return Uuid::fromString($data['$uuid']);
        }

        if (self::isTag($data, '$recordIdString') && is_string($data['$recordIdString'])) {
            return new StringRecordId($data['$recordIdString']);
        }

        if (self::isRecordId($data)) {
            return self::decodeRecordId($data['$recordId']);
        }

        if (self::isTag($data, '$table') && is_string($data['$table'])) {
            return new Table($data['$table']);
        }

        if (self::isTag($data, '$geometry') && is_array($data['$geometry'])) {
            return Geometry::fromGeoJson($data['$geometry']);
        }

        if (self::isTag($data, '$set') && is_array($data['$set'])) {
            return new Set(array_map(self::decode(...), $data['$set']));
        }

        if (self::isFile($data)) {
            return new File($data['$file']['bucket'], $data['$file']['key']);
        }

        if (self::isRange($data)) {
            return new Range(
                self::decodeBound($data['$range']['begin'] ?? null),
                self::decodeBound($data['$range']['end'] ?? null),
            );
        }

        if (self::isTag($data, '$bytes') && is_string($data['$bytes'])) {
            return Bytes::fromBase64($data['$bytes']);
        }

        if (self::isTag($data, '$future') && is_string($data['$future'])) {
            return new Future($data['$future']);
        }

        if (self::isTag($data, '$object') && is_array($data['$object'])) {
            return array_map(self::decode(...), $data['$object']);
        }

        return array_map(self::decode(...), $data);
    }

    /**
     * @param array{tb: string, id: mixed} $payload
     *
     * @return RecordId<string>|RecordIdRange<string>
     */
    private static function decodeRecordId(array $payload): RecordId|RecordIdRange
    {
        $id = self::decode($payload['id']);

        if ($id instanceof Range) {
            return new RecordIdRange($payload['tb'], $id->begin, $id->end);
        }

        return new RecordId($payload['tb'], $id);
    }

    /**
     * @return BoundIncluded<mixed>|BoundExcluded<mixed>|null
     */
    private static function decodeBound(mixed $data): BoundIncluded|BoundExcluded|null
    {
        if (!is_array($data)) {
            return null;
        }

        if (array_key_exists('$boundIncluded', $data)) {
            return new BoundIncluded(self::decode($data['$boundIncluded']));
        }

        if (array_key_exists('$boundExcluded', $data)) {
            return new BoundExcluded(self::decode($data['$boundExcluded']));
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function isTag(array $data, string $key): bool
    {
        return array_key_exists($key, $data);
    }

    /**
     * @param array<string,mixed> $data
     *
     * @phpstan-assert-if-true array{'$recordId': array{tb: string, id: mixed}} $data
     */
    private static function isRecordId(array $data): bool
    {
        return isset($data['$recordId'])
            && is_array($data['$recordId'])
            && isset($data['$recordId']['tb'])
            && is_string($data['$recordId']['tb'])
            && array_key_exists('id', $data['$recordId']);
    }

    /**
     * @param array<string,mixed> $data
     *
     * @phpstan-assert-if-true array{'$file': array{bucket: string, key: string}} $data
     */
    private static function isFile(array $data): bool
    {
        return isset($data['$file'])
            && is_array($data['$file'])
            && is_string($data['$file']['bucket'] ?? null)
            && is_string($data['$file']['key'] ?? null);
    }

    /**
     * @param array<string,mixed> $data
     *
     * @phpstan-assert-if-true array{'$range': array{begin: mixed, end: mixed}} $data
     */
    private static function isRange(array $data): bool
    {
        return isset($data['$range']) && is_array($data['$range']);
    }
}
