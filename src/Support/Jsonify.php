<?php

namespace SurrealDB\SDK\Support;

use SurrealDB\SDK\Types\Bytes;
use SurrealDB\SDK\Types\Geometry;
use SurrealDB\SDK\Types\None;
use SurrealDB\SDK\Types\Set;
use SurrealDB\SDK\Types\Table;
use SurrealDB\SDK\Types\Value;
use function is_array;

/**
 * Converts query results into plain JSON-compatible PHP values, dropping the
 * SurrealDB type information (the counterpart of the JS SDK's `jsonify`).
 *
 * Record ids, datetimes, durations, decimals, uuids, files and ranges become
 * their SurrealQL string forms, tables become their names, geometries become
 * GeoJSON arrays, sets become lists, bytes become base64 and NONE becomes null.
 */
final class Jsonify
{
    public static function value(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::value(...), $value),
            $value instanceof None => null,
            $value instanceof Table => $value->name,
            $value instanceof Geometry => $value->toGeoJson(),
            $value instanceof Set => array_map(self::value(...), $value->values),
            $value instanceof Bytes => $value->toBase64(),
            $value instanceof Value => (string) $value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::RFC3339_EXTENDED),
            default => $value,
        };
    }
}
