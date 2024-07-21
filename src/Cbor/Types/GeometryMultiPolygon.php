<?php

namespace Surreal\Cbor\Types;

class GeometryMultiPolygon extends AbstractGeometry
{
    /**
     * @var array<GeometryPolygon, array<GeometryPolygon>> $polygons
     */
    public array $polygons;

    public function __construct(array|GeometryMultiPolygon $polygons)
    {
        $polygons = $polygons instanceof GeometryMultiPolygon ? $polygons->polygons : $polygons;

        $this->polygons = array_map(
            fn($polygon) => $polygon instanceof GeometryPolygon ? $polygon : new GeometryPolygon($polygon),
            $polygons
        );
    }

    public function jsonSerialize(): array
    {
        return [
            "type" => "MultiPolygon",
            "coordinates" => $this->getCoordinates()
        ];
    }

    public function getCoordinates(): mixed
    {
        return array_map(
            fn(GeometryPolygon $polygon) => $polygon->getCoordinates(),
            $this->polygons
        );
    }

    /**
     * @return self
     */
    public function clone(): self
    {
        return new self($this->polygons);
    }

    public function is(AbstractGeometry $geometry): bool
    {
        if(!($geometry instanceof GeometryMultiPolygon)) {
            return false;
        }

        if(count($this->polygons) !== count($geometry->polygons)) {
            return false;
        }

        foreach($this->polygons as $i => $polygon) {
            if(!$polygon->is($geometry->polygons[$i])) {
                return false;
            }
        }

        return true;
    }
}