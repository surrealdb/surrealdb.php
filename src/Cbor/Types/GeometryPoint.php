<?php

namespace Surreal\Cbor\Types;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final class GeometryPoint extends AbstractGeometry
{
    /**
     * @var BigDecimal[]
     */
    public readonly array $point;

    /**
     * @param array<int,BigDecimal>|GeometryPoint $point $point
     * @throws MathException
     */
    public function __construct(array|GeometryPoint $point)
    {
        $point = $point instanceof GeometryPoint ? $point->point : $point;

        $this->point = [
            $point[0] instanceof BigDecimal ? $point[0] : BigDecimal::of($point[0]),
            $point[1] instanceof BigDecimal ? $point[1] : BigDecimal::of($point[1])
        ];
    }

    public function jsonSerialize(): array
    {
        return [
            "type" => "Point",
            "coordinates" => $this->getCoordinates()
        ];
    }

    /**
     * @return BigDecimal[]
     */
    public function getCoordinates(): array
    {
        return $this->point;
    }
}