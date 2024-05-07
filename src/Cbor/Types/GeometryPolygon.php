<?php

namespace Surreal\Cbor\Types;

use Brick\Math\Exception\MathException;

final class GeometryPolygon extends AbstractGeometry
{
    /**
     * [line, line, ...line[]]
     * @var GeometryLine[]
     */
    public readonly array $polygon;

    /**
     * @throws MathException
     */
    public function __construct(array|GeometryPolygon $polygon)
    {
        $polygon = $polygon instanceof GeometryPolygon ? $polygon->polygon : $polygon;

        $this->polygon = array_map(
            fn($line) => new GeometryLine($line),
            $polygon
        );
    }

    public function jsonSerialize(): array
    {
        return [
            "type" => "Polygon",
            "coordinates" => $this->getCoordinates()
        ];
    }

    public function getCoordinates(): array
    {
        return array_map(
            fn(GeometryLine $line) => $line->getCoordinates(),
            $this->polygon
        );
    }
}