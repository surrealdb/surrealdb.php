<?php

namespace Surreal\Cbor\Helpers;

use Beau\CborPHP\classes\TaggedValue;
use Surreal\Cbor\Abstract\AbstractBound;
use Surreal\Cbor\Enums\CustomTag;
use Surreal\Cbor\Interfaces\BoundInterface;
use Surreal\Cbor\Types\Bound\BoundExcluded;
use Surreal\Cbor\Types\Bound\BoundIncluded;
use Surreal\Cbor\Types\None;
use Surreal\Cbor\Types\Range;
use Surreal\Core\Utils\Helpers;

class RangeHelper
{
    public static function getRangeJoin(
        AbstractBound $begin,
        AbstractBound $end
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
        if(is_null($bound) || $bound instanceof None) {
            return "";
        }

        $value = Helpers::toSurrealQLString($bound->value);

        return match (true) {
            $bound instanceof Range => "(" . $value . ")",
            default => $value,
        };
    }

    /**
     * @param (TaggedValue|null)[] $range
     * @return array
     */
    public static function cborToRange(array $range): array
    {
        function decodeBound(?TaggedValue $bound): BoundExcluded|BoundIncluded|None
        {
            return match(true) {
                is_null($bound) => new None(),
                $bound->tag === CustomTag::BOUND_INCLUDED->value => new BoundIncluded($bound->value),
                $bound->tag === CustomTag::BOUND_EXCLUDED->value => new BoundExcluded($bound->value),
                default => throw new \Exception("Invalid bound tag"),
            };
        }

        return [
            decodeBound($range[0]),
            decodeBound($range[1]),
        ];
    }

    /**
     * @param array $data
     * @return array
     */
    public static function rangeToCbor(array $data): array
    {
        function encodeBound(mixed $bound): ?TaggedValue
        {
            return match (get_class($bound)) {
                BoundIncluded::class => new TaggedValue(CustomTag::BOUND_INCLUDED->value, $bound->value),
                BoundExcluded::class => new TaggedValue(CustomTag::BOUND_EXCLUDED->value, $bound->value),
                default => null,
            };
        }

        return [
            encodeBound($data[0]),
            encodeBound($data[1]),
        ];
    }
}