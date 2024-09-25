<?php

namespace Surreal\Cbor\Classes;

final class BoundExcluded
{
    public mixed $value;

    public function __construct(mixed $value)
    {
        $this->value = $value;
    }
}