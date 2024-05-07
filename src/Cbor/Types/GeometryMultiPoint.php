<?php

namespace Surreal\Cbor\Types;

use Brick\Math\Exception\MathException;

class GeometryMultiPoint extends AbstractGeometry
{
    /**
     * @var GeometryPoint[]
     */
    public readonly array $points;

    /**
     * @throws MathException
     */
    public function __construct(array|GeometryMultiPoint $multiPoint)
    {
        $multiPoint = $multiPoint instanceof GeometryMultiPoint ? $multiPoint->points : $multiPoint;

        $this->points = array_map(
            fn($point) => $point instanceof GeometryPoint ? $point : new GeometryPoint($point),
            $multiPoint
        );
    }

    public function jsonSerialize(): array
    {
        return [
            "type" => "MultiPoint",
            "coordinates" => $this->getCoordinates()
        ];
    }

    public function getCoordinates(): mixed
    {
        return array_map(
            fn(GeometryPoint $point) => $point->getCoordinates(),
            $this->points
        );
    }
}