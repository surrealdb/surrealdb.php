<?php

namespace SurrealDB\Types;

use function count;

/**
 * A GeoJSON `Polygon` geometry: one or more linear rings.
 */
final class GeometryPolygon extends Geometry
{
    /** @var list<GeometryLine> */
    public readonly array $rings;

    public function __construct(GeometryLine $exterior, GeometryLine ...$interior)
    {
        $this->rings = [$exterior, ...$interior];
    }

    /**
     * @param array<string,mixed> $json
     */
    public static function fromGeoJson(array $json): self
    {
        /** @var list<list<array{0: int|float, 1: int|float}>> $coordinates */
        $coordinates = $json['coordinates'];
        $rings = array_map(
            static fn (array $ring): GeometryLine => GeometryLine::fromGeoJson(['coordinates' => $ring]),
            $coordinates,
        );

        return new self(...$rings);
    }

    /**
     * @return list<list<array{float, float}>>
     */
    public function coordinates(): array
    {
        return array_map(static fn (GeometryLine $ring): array => $ring->coordinates(), $this->rings);
    }

    /**
     * @return array{type: 'Polygon', coordinates: list<list<array{float, float}>>}
     */
    public function toGeoJson(): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => $this->coordinates(),
        ];
    }

    public function is(Geometry $geometry): bool
    {
        if (!$geometry instanceof self || count($this->rings) !== count($geometry->rings)) {
            return false;
        }

        foreach ($this->rings as $index => $ring) {
            if (!$ring->is($geometry->rings[$index])) {
                return false;
            }
        }

        return true;
    }
}
