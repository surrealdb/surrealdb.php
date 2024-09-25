<?php

namespace Surreal\Cbor\Classes;

final class BoundIncluded
{
    public mixed $value;

    public function __construct(mixed $value)
    {
        $this->value = $value;
    }
}