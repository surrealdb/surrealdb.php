<?php

namespace SurrealDB\Support;

use SurrealDB\Types\Bytes;
use SurrealDB\Types\Geometry;
use SurrealDB\Types\None;
use SurrealDB\Types\Set;
use SurrealDB\Types\Table;
use SurrealDB\Types\Value;
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
