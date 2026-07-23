<?php

namespace SurrealDB\Types;

use SurrealDB\Exceptions\InvalidValueException;

/**
 * Base class for SurrealQL `geometry` values (RFC 7946 / GeoJSON).
 *
 * Geometries are lightweight value objects rather than a wrapper around a heavy
 * geospatial dependency (such libraries require the GEOS native extension):
 * SurrealDB only needs the GeoJSON structure, which these classes model
 * directly, mirroring the JS SDK's `Geometry` family.
 */
abstract class Geometry extends Value
{
    /**
     * The GeoJSON representation of this geometry.
     *
     * @return array<string,mixed>
     */
    abstract public function toGeoJson(): array;

    /**
     * Structural equality against another geometry of the same kind.
     */
    abstract public function is(Geometry $geometry): bool;

    public function equals(mixed $other): bool
    {
        return $other instanceof Geometry && $this->is($other);
    }

    public function __toString(): string
    {
        return json_encode($this->toGeoJson(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Inline SurrealQL literal form: the GeoJSON object literal.
     */
    public function escape(): string
    {
        return self::toSurql($this->toGeoJson());
    }

    /**
     * @return array{'$geometry': array<string,mixed>}
     */
    public function jsonSerialize(): array
    {
        return ['$geometry' => $this->toGeoJson()];
    }

    /**
     * Build the concrete geometry for a GeoJSON object.
     *
     * @param array<string,mixed> $json
     */
    public static function fromGeoJson(array $json): Geometry
    {
        return match ($json['type'] ?? null) {
            'Point' => GeometryPoint::fromGeoJson($json),
            'LineString' => GeometryLine::fromGeoJson($json),
            'Polygon' => GeometryPolygon::fromGeoJson($json),
            'MultiPoint' => GeometryMultiPoint::fromGeoJson($json),
            'MultiLineString' => GeometryMultiLine::fromGeoJson($json),
            'MultiPolygon' => GeometryMultiPolygon::fromGeoJson($json),
            'GeometryCollection' => GeometryCollection::fromGeoJson($json),
            default => throw new InvalidValueException(
                'Unknown GeoJSON geometry type: ' . var_export($json['type'] ?? null, true),
            ),
        };
    }
}
