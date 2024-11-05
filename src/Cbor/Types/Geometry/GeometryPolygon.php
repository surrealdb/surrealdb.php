<?php

namespace Surreal\Cbor\Types\Geometry;

use Brick\Math\Exception\MathException;
use Surreal\Cbor\Abstract\AbstractGeometry;

final class GeometryPolygon extends AbstractGeometry
{
    /**
     * [line, line, ...line[]]
     * @var GeometryLine[]
     */
    public array $polygon;

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

    /**
     * @throws MathException
     */
    public function clone(): self
    {
        return new self($this->polygon);
    }

    public function is(AbstractGeometry $geometry): bool
    {
        if(!($geometry instanceof GeometryPolygon)) {
            return false;
        }

        if(count($this->polygon) !== count($geometry->polygon)) {
            return false;
        }

        foreach($this->polygon as $index => $line) {
            if(!$line->is($geometry->polygon[$index])) {
                return false;
            }
        }

        return true;
    }
}