<?php

namespace Surreal\Cbor\Types;

use Brick\Math\Exception\MathException;

class GeometryLine extends AbstractGeometry
{
    /**
     * @var array{GeometryPoint, GeometryPoint}
     */
    public readonly array $line;

    /**
     * @throws MathException
     */
    public function __construct(array|GeometryLine $line)
    {

        $line = $line instanceof GeometryLine ? $line->line : $line;

        $this->line = [
            $line[0] instanceof GeometryPoint ? $line[0] : new GeometryPoint($line[0]),
            $line[1] instanceof GeometryPoint ? $line[1] : new GeometryPoint($line[1])
        ];
    }

    public function jsonSerialize(): array
    {
        [$x, $y] = $this->line;

        return [
            "type" => "LineString",
            "coordinates" => $this->getCoordinates()
        ];
    }

    public function getCoordinates(): array
    {
        return array_map(
            fn(GeometryPoint $point) => $point->getCoordinates(),
            $this->line
        );
    }
}