<?php

namespace Surreal\Cbor\Types\Geometry;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Surreal\Cbor\Abstract\AbstractGeometry;

final class GeometryPoint extends AbstractGeometry
{
    /**
     * @var (int|float)[]
     */
    public array $point;

    /**
     * @param array<int,BigDecimal>|GeometryPoint $point $point
     * @throws MathException
     */
    public function __construct(array|GeometryPoint $point)
    {
        $point = $point instanceof GeometryPoint ? $point->point : $point;

        if($point instanceof GeometryPoint) {
            $this->point = $point->clone()->point;
            return;
        }

        $this->point = [
            $this->parseDecimal($point[0]),
            $this->parseDecimal($point[1])
        ];
    }

    private function parseDecimal(int|BigDecimal $value): int|float
    {
        if($value instanceof BigDecimal) {
            return $value->toFloat();
        }

        return $value;
    }

    public function jsonSerialize(): array
    {
        return [
            "type" => "Point",
            "coordinates" => $this->getCoordinates()
        ];
    }

    /**
     * @return int[]
     */
    public function getCoordinates(): array
    {
        return $this->point;
    }

    /**
     * @throws MathException
     */
    public function clone(): self
    {
        return new self($this->point);
    }

    public function is(AbstractGeometry $geometry): bool
    {
        if(!($geometry instanceof GeometryPoint)) {
            return false;
        }

        return $this->point[0] === $geometry->point[0] && $this->point[1] === $geometry->point[1];
    }
}