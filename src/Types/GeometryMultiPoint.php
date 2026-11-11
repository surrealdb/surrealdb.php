<?php

namespace SurrealDB\Types;

use function count;

/**
 * A GeoJSON `MultiPoint` geometry: a collection of points.
 */
final class GeometryMultiPoint extends Geometry
{
    /** @var list<GeometryPoint> */
    public readonly array $points;

    public function __construct(GeometryPoint $first, GeometryPoint ...$rest)
    {
        $this->points = [$first, ...$rest];
    }

    /**
     * @param array<string,mixed> $json
     */
    public static function fromGeoJson(array $json): self
    {
        /** @var list<array{0: int|float, 1: int|float}> $coordinates */
        $coordinates = $json['coordinates'];
        $points = array_map(GeometryPoint::fromCoordinates(...), $coordinates);

        return new self(...$points);
    }

    /**
     * @return list<array{float, float}>
     */
    public function coordinates(): array
    {
        return array_map(static fn (GeometryPoint $point): array => $point->coordinates(), $this->points);
    }

    /**
     * @return array{type: 'MultiPoint', coordinates: list<array{float, float}>}
     */
    public function toGeoJson(): array
    {
        return [
            'type' => 'MultiPoint',
            'coordinates' => $this->coordinates(),
        ];
    }

    public function is(Geometry $geometry): bool
    {
        if (!$geometry instanceof self || count($this->points) !== count($geometry->points)) {
            return false;
        }

        foreach ($this->points as $index => $point) {
            if (!$point->is($geometry->points[$index])) {
                return false;
            }
        }

        return true;
    }
}
