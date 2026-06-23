<?php

namespace SurrealDB\SDK\Types;

/**
 * A GeoJSON `Point` geometry: a single `[longitude, latitude]` coordinate.
 */
final class GeometryPoint extends Geometry
{
    public function __construct(
        public readonly float $longitude,
        public readonly float $latitude,
    ) {}

    /**
     * @param array{0: int|float, 1: int|float} $point
     */
    public static function fromCoordinates(array $point): self
    {
        return new self((float) $point[0], (float) $point[1]);
    }

    /**
     * @param array<string,mixed> $json
     */
    public static function fromGeoJson(array $json): self
    {
        /** @var array{0: int|float, 1: int|float} $coordinates */
        $coordinates = $json['coordinates'];

        return self::fromCoordinates($coordinates);
    }

    /**
     * @return array{float, float}
     */
    public function coordinates(): array
    {
        return [$this->longitude, $this->latitude];
    }

    /**
     * @return array{type: 'Point', coordinates: array{float, float}}
     */
    public function toGeoJson(): array
    {
        return [
            'type' => 'Point',
            'coordinates' => $this->coordinates(),
        ];
    }

    public function is(Geometry $geometry): bool
    {
        return $geometry instanceof self
            && $this->longitude === $geometry->longitude
            && $this->latitude === $geometry->latitude;
    }
}
