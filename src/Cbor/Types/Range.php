<?php

namespace Surreal\Cbor\Types;

use Surreal\Cbor\Helpers\RangeHelper;
use Surreal\Cbor\Types\Bound\BoundExcluded;
use Surreal\Cbor\Types\Bound\BoundIncluded;

class Range implements \JsonSerializable
{
    public BoundIncluded|BoundExcluded $begin;
    public BoundIncluded|BoundExcluded $end;

    public function __construct(BoundIncluded|BoundExcluded $begin, BoundIncluded|BoundExcluded $end)
    {
        $this->begin = $begin;
        $this->end = $end;
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
        $begin = RangeHelper::escapeRangeBound($this->begin);
        $end = RangeHelper::escapeRangeBound($this->end);

        return $begin . RangeHelper::getRangeJoin($this->begin, $this->end) . $end;
    }
}