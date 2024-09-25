<?php

namespace Surreal\Cbor\Helpers;

use Surreal\Cbor\Classes\BoundExcluded;
use Surreal\Cbor\Classes\BoundIncluded;
use Surreal\Cbor\Types\Range;
use Surreal\Core\Utils\Helpers;

class RangeHelper
{
    public static function getRangeJoin(
        BoundIncluded | BoundExcluded $begin,
        BoundIncluded | BoundExcluded $end
    ): string
    {
        $output = "";

        if($begin instanceof BoundExcluded) {
            $output .= ">";
        }

        $output .= "..";

        if($end instanceof BoundExcluded) {
            $output .= "=";
        }

        return $output;
    }

    public static function isValidIdBound(mixed $bound): bool
    {
        if($bound instanceof BoundIncluded || $bound instanceof BoundExcluded) {
            return self::isValidIdBound($bound->value);
        }

        return true;
    }

    public static function escapeIdBound(mixed $bound): string
    {
        if($bound instanceof BoundIncluded || $bound instanceof BoundExcluded) {
            return RecordIdHelper::escapeIdPart($bound->value);
        }

        return "";
    }

    public static function escapeRangeBound(mixed $bound): string
    {
        if(is_null($bound)) {
            return "";
        }

        $value = $bound->value;

        if($bound instanceof Range) {
            return "(" . Helpers::toSurrealQLString($value) . ")";
        }

        return RecordIdHelper::toSurrealqlString($value);
    }
}