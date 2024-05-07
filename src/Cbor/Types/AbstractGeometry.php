<?php

namespace Surreal\Cbor\Types;

use JsonSerializable;

abstract class AbstractGeometry implements JsonSerializable
{
    /**
     * @return array{type:string,coordinates:array}
     */
    abstract public function jsonSerialize(): array;

    abstract public function getCoordinates(): mixed;
}