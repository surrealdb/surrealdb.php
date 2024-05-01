<?php

namespace Surreal\Cbor\Types;

use Surreal\Cbor\Types\AbstractGeometry;

final class GeometryCollection extends AbstractGeometry
{
    public readonly array $collection;

    public function __construct(array|GeometryCollection $collection)
    {
        $this->collection = $collection instanceof GeometryCollection
            ? $collection->collection
            : $collection;
    }
}