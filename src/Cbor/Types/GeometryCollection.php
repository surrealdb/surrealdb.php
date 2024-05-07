<?php

namespace Surreal\Cbor\Types;

class GeometryCollection extends AbstractGeometry
{
    public readonly array $collection;

    public function __construct(array|GeometryCollection $collection)
    {
        $this->collection = $collection instanceof GeometryCollection
            ? $collection->collection
            : $collection;
    }

    public function jsonSerialize(): array
    {
        return [
            "type" => "GeometryCollection",
            "geometries" => $this->getCoordinates()
        ];
    }

    public function getCoordinates(): mixed
    {
        return array_map(
            fn(AbstractGeometry $geometry) => $geometry->jsonSerialize(),
            $this->collection
        );
    }
}