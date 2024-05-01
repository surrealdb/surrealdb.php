<?php

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final class Decimal
{
    private BigDecimal $decimal;

    /**
     * @throws MathException
     */
    public function __construct(BigDecimal|float|string $decimal)
    {
        if($decimal instanceof BigDecimal) {
            $this->decimal = $decimal;
        } else {
            $this->decimal = BigDecimal::of($decimal);
        }
    }

    /**
     * @throws MathException
     */
    public static function from(string|float $decimal): BigDecimal
    {
        return BigDecimal::of($decimal);
    }

    public function getDecimal(): BigDecimal
    {
        return $this->decimal;
    }
}