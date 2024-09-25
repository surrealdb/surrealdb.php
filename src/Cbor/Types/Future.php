<?php

namespace Surreal\Cbor\Types;

use JsonSerializable;

class Future implements JsonSerializable
{
    private string $inner;

    public function __construct(string $inner)
    {
        $this->inner = $inner;
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function toString(): string
    {
        return "<future> { $this->inner }";
    }
}