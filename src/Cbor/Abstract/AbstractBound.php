<?php

namespace Surreal\Cbor\Abstract;

use Surreal\Cbor\Interfaces\BoundInterface;

abstract class AbstractBound implements BoundInterface
{
    public mixed $value;

    public function __construct(mixed $value)
    {
        $this->value = $value;
    }
}