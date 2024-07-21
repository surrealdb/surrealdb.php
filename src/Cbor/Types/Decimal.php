<?php

namespace Surreal\Cbor\Types;

use JsonSerializable;

readonly class Decimal implements JsonSerializable
{
    public float $value;

    public function __construct(int|float|string|Decimal $value)
    {
        if($value instanceof Decimal) {
            $this->value = $value->value;
        } elseif(is_int($value) || is_string($value)) {
            $this->value = floatval($value);
        } elseif(is_float($value)) {
            $this->value = $value;
        } else {
            throw new \InvalidArgumentException('Invalid value type');
        }
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function toString(): string
    {
        return (string) $this->value;
    }

    public function equals(Decimal|int|float|string $decimal): bool
    {
        if($decimal instanceof Decimal) {
            return $this->value === $decimal->value;
        }

        $decimal = new Decimal($decimal);
        return $this->value === $decimal->value;
    }

    public function jsonSerialize(): mixed
    {
        return $this->value->jsonSerialize();
    }
}