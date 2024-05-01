<?php

namespace Surreal\Cbor\Types;

final class GeometryMultiPolygon extends AbstractGeometry
{
    /**
     * @var array<GeometryPolygon, array<GeometryPolygon>> $polygons
     */
    public readonly array $polygons;

    public function __construct(array|GeometryMultiPolygon $polygons)
    {
        $polygons = $polygons instanceof GeometryMultiPolygon ? $polygons->polygons : $polygons;

        $this->polygons = array_map(
            fn($polygon) => $polygon instanceof GeometryPolygon ? $polygon : new GeometryPolygon($polygon),
            $polygons
        );
    }
}