<?php

namespace Surreal\Cbor\Types;

use Brick\Math\Exception\MathException;
use Decimal;

final class GeometryPoint extends AbstractGeometry
{
    /**
     * @var Decimal[]
     */
    public readonly array $point;

    /**
     * @param array<int,Decimal>|GeometryPoint $point $point
     * @throws MathException
     */
    public function __construct(array|GeometryPoint $point)
    {
        $point = $point instanceof GeometryPoint ? $point->point : $point;

        $this->point = [
            $point[0] instanceof Decimal ? $point[0] : new Decimal($point[0]),
            $point[1] instanceof Decimal ? $point[1] : new Decimal($point[1])
        ];
    }
}