<?php

namespace Surreal\Cbor\Types;

class GeometryCollection extends AbstractGeometry
{
    public array $collection;

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

    /**
     * Returns a clone of the collection.
     * @return $this
     */
    public function clone(): GeometryCollection
    {
        return new GeometryCollection($this->collection);
    }

    public function getCoordinates(): mixed
    {
        return array_map(
            fn(AbstractGeometry $geometry) => $geometry->jsonSerialize(),
            $this->collection
        );
    }

    public function is(AbstractGeometry $geometry): bool
    {
        if(!($geometry instanceof GeometryCollection)) {
            return false;
        }

        if(count($this->collection) !== count($geometry->collection)) {
            return false;
        }

        foreach($this->collection as $i => $geometry) {
            if(!$geometry->is($geometry->collection[$i])) {
                return false;
            }
        }

        return true;
    }
}