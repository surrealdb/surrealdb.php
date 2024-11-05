<?php

namespace Surreal\Cbor\Types\Geometry;

use Brick\Math\Exception\MathException;
use Surreal\Cbor\Abstract\AbstractGeometry;

class GeometryLine extends AbstractGeometry
{
    /**
     * @var array{GeometryPoint, GeometryPoint}
     */
    public array $line;

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
        return [
            "type" => "LineString",
            "coordinates" => $this->getCoordinates()
        ];
    }

    public function close(): void
    {
        if(!$this->line[0]->is(end($this->line))) {
            $this->line[] = $this->line[0];
        }
    }

    public function is(AbstractGeometry $geometry): bool
    {
        if(!($geometry instanceof GeometryLine))  {
            return false;
        }

        if(count($this->line) !== count($geometry->line)) {
            return false;
        }

        foreach($this->line as $index => $point) {
            if(!$point->is($geometry->line[$index])) {
                return false;
            }
        }

        return true;
    }

    public function getCoordinates(): array
    {
        return array_map(
            fn(GeometryPoint $point) => $point->getCoordinates(),
            $this->line
        );
    }

    /**
     * @throws MathException
     */
    public function clone(): static
    {
        return new static($this->line);
    }
}