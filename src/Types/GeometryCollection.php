<?php

namespace SurrealDB\Types;

use function count;

/**
 * A GeoJSON `GeometryCollection`: a heterogeneous collection of geometries.
 */
final class GeometryCollection extends Geometry
{
    /** @var list<Geometry> */
    public readonly array $geometries;

    public function __construct(Geometry $first, Geometry ...$rest)
    {
        $this->geometries = [$first, ...$rest];
    }

    /**
     * @param array<string,mixed> $json
     */
    public static function fromGeoJson(array $json): self
    {
        /** @var list<array<string,mixed>> $geometries */
        $geometries = $json['geometries'];
        $members = array_map(Geometry::fromGeoJson(...), $geometries);

        return new self(...$members);
    }

    /**
     * @return array{type: 'GeometryCollection', geometries: list<array<string,mixed>>}
     */
    public function toGeoJson(): array
    {
        return [
            'type' => 'GeometryCollection',
            'geometries' => array_map(static fn (Geometry $geometry): array => $geometry->toGeoJson(), $this->geometries),
        ];
    }

    public function is(Geometry $geometry): bool
    {
        if (!$geometry instanceof self || count($this->geometries) !== count($geometry->geometries)) {
            return false;
        }

        foreach ($this->geometries as $index => $member) {
            if (!$member->is($geometry->geometries[$index])) {
                return false;
            }
        }

        return true;
    }
}
