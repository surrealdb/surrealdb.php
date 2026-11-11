<?php

namespace SurrealDB\Types;

use function count;

/**
 * A GeoJSON `MultiPolygon` geometry: a collection of polygons.
 */
final class GeometryMultiPolygon extends Geometry
{
    /** @var list<GeometryPolygon> */
    public readonly array $polygons;

    public function __construct(GeometryPolygon $first, GeometryPolygon ...$rest)
    {
        $this->polygons = [$first, ...$rest];
    }

    /**
     * @param array<string,mixed> $json
     */
    public static function fromGeoJson(array $json): self
    {
        /** @var list<list<list<array{0: int|float, 1: int|float}>>> $coordinates */
        $coordinates = $json['coordinates'];
        $polygons = array_map(
            static fn (array $polygon): GeometryPolygon => GeometryPolygon::fromGeoJson(['coordinates' => $polygon]),
            $coordinates,
        );

        return new self(...$polygons);
    }

    /**
     * @return list<list<list<array{float, float}>>>
     */
    public function coordinates(): array
    {
        return array_map(static fn (GeometryPolygon $polygon): array => $polygon->coordinates(), $this->polygons);
    }

    /**
     * @return array{type: 'MultiPolygon', coordinates: list<list<list<array{float, float}>>>}
     */
    public function toGeoJson(): array
    {
        return [
            'type' => 'MultiPolygon',
            'coordinates' => $this->coordinates(),
        ];
    }

    public function is(Geometry $geometry): bool
    {
        if (!$geometry instanceof self || count($this->polygons) !== count($geometry->polygons)) {
            return false;
        }

        foreach ($this->polygons as $index => $polygon) {
            if (!$polygon->is($geometry->polygons[$index])) {
                return false;
            }
        }

        return true;
    }
}
