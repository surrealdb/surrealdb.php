<?php

namespace SurrealDB\SDK\Types;

use function count;

/**
 * A GeoJSON `MultiLineString` geometry: a collection of lines.
 */
final class GeometryMultiLine extends Geometry
{
    /** @var list<GeometryLine> */
    public readonly array $lines;

    public function __construct(GeometryLine $first, GeometryLine ...$rest)
    {
        $this->lines = [$first, ...$rest];
    }

    /**
     * @param array<string,mixed> $json
     */
    public static function fromGeoJson(array $json): self
    {
        /** @var list<list<array{0: int|float, 1: int|float}>> $coordinates */
        $coordinates = $json['coordinates'];
        $lines = array_map(
            static fn (array $line): GeometryLine => GeometryLine::fromGeoJson(['coordinates' => $line]),
            $coordinates,
        );

        return new self(...$lines);
    }

    /**
     * @return list<list<array{float, float}>>
     */
    public function coordinates(): array
    {
        return array_map(static fn (GeometryLine $line): array => $line->coordinates(), $this->lines);
    }

    /**
     * @return array{type: 'MultiLineString', coordinates: list<list<array{float, float}>>}
     */
    public function toGeoJson(): array
    {
        return [
            'type' => 'MultiLineString',
            'coordinates' => $this->coordinates(),
        ];
    }

    public function is(Geometry $geometry): bool
    {
        if (!$geometry instanceof self || count($this->lines) !== count($geometry->lines)) {
            return false;
        }

        foreach ($this->lines as $index => $line) {
            if (!$line->is($geometry->lines[$index])) {
                return false;
            }
        }

        return true;
    }
}
