<?php

namespace Surreal\Cbor\Abstract;

use JsonSerializable;

abstract class AbstractGeometry implements JsonSerializable
{
    /**
     * @return array{type:string,coordinates:array}
     */
    abstract public function jsonSerialize(): array;
    abstract public function getCoordinates(): mixed;
    abstract public function clone();
    abstract public function is(AbstractGeometry $geometry): bool;
}