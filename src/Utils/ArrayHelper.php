<?php

namespace Surreal\Utils;

class ArrayHelper
{
    public static function isAssoc(mixed $data): bool
    {
        if ([] === $data || !is_array($data)) {
            return false;
        }

        return array_keys($data) !== range(0, count($data) - 1);
    }
}