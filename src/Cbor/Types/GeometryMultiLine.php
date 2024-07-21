<?php

namespace Surreal\Cbor\Types;

use Brick\Math\Exception\MathException;

class GeometryMultiLine extends AbstractGeometry
{
    /**
     * [GeometryLine, ...GeometryLine[]]
     * @var GeometryLine[]
     */
    public array $lines;

    /**
     * @throws MathException
     */
    public function __construct(array|GeometryMultiLine $lines)
    {
        $lines = $lines instanceof GeometryMultiLine ? $lines->lines : $lines;

        $this->lines = array_map(
            fn($line) => $line instanceof GeometryLine ? $line : new GeometryLine($line),
            $lines
        );
    }

    public function jsonSerialize(): array
    {
        return [
            "type" => "MultiLineString",
            "coordinates" => $this->getCoordinates()
        ];
    }

    public function getCoordinates(): mixed
    {
        return array_map(
            fn(GeometryLine $line) => $line->getCoordinates(),
            $this->lines
        );
    }

    /**
     * @throws MathException
     */
    public function clone(): GeometryMultiLine
    {
        return new self($this->lines);
    }

    public function is(AbstractGeometry $geometry): bool
    {
        if(!($geometry instanceof GeometryMultiLine)) {
            return false;
        }

        if(count($this->lines) !== count($geometry->lines)) {
            return false;
        }

        foreach($this->lines as $i => $line) {
            if(!$line->is($geometry->lines[$i])) {
                return false;
            }
        }

        return true;
    }
}